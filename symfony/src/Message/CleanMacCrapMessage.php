<?php

namespace App\Message;

/**
 * Demande de nettoyage des fichiers parasites macOS (._* et .DS_Store) sur les mounts NFS.
 * Dispatched par ArgosSchedule (cron quotidien 3h30) + bouton manuel.
 */
final class CleanMacCrapMessage
{
}
