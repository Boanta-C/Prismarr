<?php

namespace App\Controller;

use App\Service\ConfigService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Home page — picks the best default landing page based on which services
 * are configured. Avoids a redirect loop when the user skipped TMDb during
 * the setup wizard (ServiceRouteGuardSubscriber would bounce them back to
 * /setup/tmdb if we redirected there unconditionally).
 */
class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function index(ConfigService $config): Response
    {
        if ($config->has('tmdb_api_key')) {
            return $this->redirectToRoute('tmdb_index');
        }
        if ($config->has('radarr_api_key')) {
            return $this->redirectToRoute('app_media_films');
        }
        if ($config->has('sonarr_api_key')) {
            return $this->redirectToRoute('app_media_series');
        }
        if ($config->has('qbittorrent_url')) {
            return $this->redirectToRoute('app_qbittorrent_index');
        }

        return $this->render('home/welcome.html.twig');
    }
}
