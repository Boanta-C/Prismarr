<?php

namespace App\MessageHandler;

use App\Entity\Infrastructure\Device;
use App\Entity\Infrastructure\Metric;
use App\Entity\Infrastructure\ServiceStatus;
use App\Message\PollUnifiMessage;
use App\Service\UniFi\UniFiClient;
use App\Service\WorkerHealthService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class PollUnifiHandler
{
    private EntityManagerInterface $em;

    public function __construct(
        private readonly UniFiClient         $unifi,
        private readonly ManagerRegistry     $doctrine,
        private readonly LoggerInterface     $logger,
        private readonly HubInterface        $hub,
        private readonly WorkerHealthService $health,
    ) {
        $this->em = $doctrine->getManager();
    }

    public function __invoke(PollUnifiMessage $message): void
    {
        $this->resetEmIfClosed();

        try {
            $data = $this->unifi->getData();

            if ($data === null) {
                $this->logger->warning('PollUnifiHandler : getData() a retourné null (UniFi inaccessible ou rate-limitée).');
                $this->health->reportFailure('worker:unifi', 'mac-mini', 'UniFi inaccessible (429 ou erreur réseau).');
                return;
            }

            $device = $this->getOrCreateUnifiDevice($data['ap']);

            foreach ($data['health'] as $subsystem => $status) {
                $this->upsertService($device, 'unifi.health.' . $subsystem, $status === 'ok' ? 'up' : 'down');
            }

            if ($data['ap'] !== null) {
                $this->addMetric($device, 'unifi.ap.uptime',   $data['ap']['uptime'],   's');
                $this->addMetric($device, 'unifi.ap.tx_bytes', $data['ap']['tx_bytes'], 'bytes');
                $this->addMetric($device, 'unifi.ap.rx_bytes', $data['ap']['rx_bytes'], 'bytes');

                if ($data['ap']['satisfaction'] !== null) {
                    $this->addMetric($device, 'unifi.ap.satisfaction', (float) $data['ap']['satisfaction'], '');
                }
                if ($data['ap']['num_sta'] !== null) {
                    $this->addMetric($device, 'unifi.ap.num_sta', (float) $data['ap']['num_sta'], '');
                }
            }

            $this->addMetric($device, 'unifi.clients.total', $data['client_count'], '');
            $this->addMetric($device, 'unifi.clients.wifi',  $data['wifi_count'],   '');
            $this->addMetric($device, 'unifi.clients.wired', $data['wired_count'],  '');

            $device->setStatus('online');
            $device->setLastSeenAt(new \DateTimeImmutable());
            $this->em->flush();

            $this->publishToMercure($data);
            $this->logger->info(sprintf(
                'PollUnifiHandler : %d clients (%d wifi / %d filaires), %d health subsystems.',
                $data['client_count'], $data['wifi_count'], $data['wired_count'], count($data['health'])
            ));

            $this->health->reportSuccess('worker:unifi');
        } catch (\Throwable $e) {
            $this->logger->error('PollUnifiHandler : ' . $e->getMessage());
            $this->health->reportFailure('worker:unifi', 'mac-mini', 'Worker UniFi en erreur : ' . $e->getMessage());
        }
    }

    private function resetEmIfClosed(): void
    {
        if (!$this->em->isOpen()) {
            $this->doctrine->resetManager();
            $this->em = $this->doctrine->getManager();
        }
    }

    private function publishToMercure(array $data): void
    {
        try {
            // Clients : uniquement les champs utiles à l'affichage
            $clients = array_map(fn($c) => [
                'mac'          => $c['mac'],
                'hostname'     => $c['hostname'],
                'ip'           => $c['ip'],
                'is_wired'     => $c['is_wired'],
                'signal'       => $c['signal'],
                'rssi'         => $c['rssi'],
                'tx_rate'      => $c['tx_rate'],
                'rx_rate'      => $c['rx_rate'],
                'channel'      => $c['channel'],
                'radio_proto'  => $c['radio_proto'],
                'satisfaction' => $c['satisfaction'],
                'network_name' => $c['network_name'],
                'uptime'       => $c['uptime'],
                'oui'          => $c['oui'],
            ], $data['clients']);

            // AP : résumé enrichi
            $ap = null;
            if ($data['ap'] !== null) {
                $a = $data['ap'];
                $ap = [
                    'name'         => $a['name'],
                    'model'        => $a['model'],
                    'mac'          => $a['mac'],
                    'ip'           => $a['ip'],
                    'uptime'       => $a['uptime'],
                    'version'      => $a['version'],
                    'status'       => $a['status'],
                    'satisfaction' => $a['satisfaction'],
                    'num_sta'      => $a['num_sta'],
                    'radio_table'  => $a['radio_table'],
                    'tx_bytes'     => $a['tx_bytes'],
                    'rx_bytes'     => $a['rx_bytes'],
                ];
            }

            $this->hub->publish(new Update(
                '/argos/metrics/unifi',
                json_encode([
                    'clients_total' => $data['client_count'],
                    'clients_wifi'  => $data['wifi_count'],
                    'clients_wired' => $data['wired_count'],
                    'clients'       => $clients,
                    'ap'            => $ap,
                    'last_seen'     => date('H:i:s'),
                ])
            ));
        } catch (\Throwable $e) {
            $this->logger->warning('PollUnifiHandler : Mercure publish failed — ' . $e->getMessage());
        }
    }

    private function upsertService(Device $device, string $name, string $status): void
    {
        $service = $this->em->getRepository(ServiceStatus::class)
            ->findOneBy(['device' => $device, 'name' => $name]);

        if (!$service) {
            $service = new ServiceStatus();
            $service->setDevice($device);
            $service->setName($name);
            $this->em->persist($service);
        }

        $service->setStatus($status);
        $service->setCheckedAt(new \DateTimeImmutable());
        $service->setUrl(null);
        $service->setHttpCode(null);
        $service->setResponseTimeMs(null);
    }

    private function addMetric(Device $device, string $name, float $value, string $unit): void
    {
        $metric = new Metric();
        $metric->setDevice($device);
        $metric->setName($name);
        $metric->setValue($value);
        $metric->setUnit($unit);
        $this->em->persist($metric);
    }

    private function getOrCreateUnifiDevice(?array $ap): Device
    {
        $device = $this->em->getRepository(Device::class)->findOneBy(['hostname' => 'unifi-ap']);

        if (!$device) {
            $device = new Device();
            $device->setType('ap');
            $device->setHostname('unifi-ap');
            $device->setIpAddress('192.168.10.254');
            $device->setIsMonitored(true);
            $this->em->persist($device);
        }

        $device->setName($ap['name'] ?? 'UniFi AP');

        return $device;
    }
}
