<?php

// Execute this PHP file at least once for long polling to activate

set_time_limit(0);

require_once 'PollBot.php';

define('BOT_TOKEN', 'XXXXXXXXXXX:XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX');

$bot = new PollBot(BOT_TOKEN, 'PollBotChat');
$bot->runLongpoll();
