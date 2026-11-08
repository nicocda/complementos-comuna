<?php

use Illuminate\Support\Facades\Schedule;

// Shared hosting has no process supervisor: the cron runs `schedule:run` every minute
// and this drains the queue (notification emails / database notifications) then exits.
Schedule::command('queue:work --stop-when-empty --tries=3 --max-time=50')
    ->everyMinute()
    ->withoutOverlapping(5);

Schedule::command('queue:prune-failed --hours=720')->daily();
Schedule::command('auth:clear-resets')->daily();
