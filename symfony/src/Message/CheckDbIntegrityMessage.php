<?php

namespace App\Message;

/**
 * Demande de vérification d'intégrité SQLite sur les DB des containers critiques.
 * Dispatched par ArgosSchedule (cron quotidien 3h) + bouton manuel.
 */
final class CheckDbIntegrityMessage
{
}
