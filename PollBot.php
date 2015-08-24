<?php

require_once 'TelegramBot.php';
require 'vendor/predis/predis/autoload.php';
require 'vendor/predis/predis/src/Command/SetAdd.php';



class PollBot extends TelegramBot {

  public $redis = false;

  protected static $REDIS_HOST = 'your_host_url';
  protected static $REDIS_PORT = yourport;
  protected static $REDIS_PASSWORD = 'your_password';

  public function init() {
    parent::init();
    $this->dbInit();
  }

  public function dbInit() {
    if (!$this->redis) {
      $this->redis = new Predis\Client([
    'host' => parse_url($_ENV['REDIS_URL'], PHP_URL_HOST),
    'port' => parse_url($_ENV['REDIS_URL'], PHP_URL_PORT),
    'password' => parse_url($_ENV['REDIS_URL'], PHP_URL_PASS),
]);

      }
    }
  }

class PollBotChat extends TelegramBotChat {

  protected $redis;

  protected $curPoll = false;
  protected static $optionsLimit = 10;

  public function __construct($core, $chat_id) {
    parent::__construct($core, $chat_id);
    $this->redis = $this->core->redis;
  }

  public function init() {
    $this->curPoll = $this->dbGetPoll();
  }



  public function command_start($params, $message) {
    if (!$this->isGroup) {
      $this->command_newpoll('', $message);
    } else {
      $poll_id = $params;
      $poll = $this->dbGetPollById($poll_id);
      if ($poll) {
        if ($this->curPoll) {
          $this->sendOnePollOnly();
        } else {
          if ($poll['author_id'] == $message['from']['id']) {
            $this->dbSavePoll($poll);
            $this->curPoll = $poll;
            $this->sendPoll();
          }
        }
      }
    }
  }

  public function command_newpoll($params, $message) {
    if ($this->curPoll && $this->isGroup) {
      if ($this->isGroup) {
        $this->sendOnePollOnly();
        return;
      } else {
        $this->dbDropPoll();
        $this->curPoll = false;
      }
    }

    $author_id = $message['from']['id'];
    $message_id = $message['message_id'];
    $newpoll = $this->parsePollParams($params);

    $has_title = strlen($newpoll['title']) > 0;
    $has_options = count($newpoll['options']) > 0;

    if ($has_title && $has_options) {
      $this->createPoll($author_id, $newpoll);
    } else if ($has_title) {
      $this->needPollOptions($author_id, $newpoll, $message_id);
    } else {
      $this->needPollTitle($author_id, $message_id);
    }
  }

  public function command_poll($params, $message) {
    if (!$this->isGroup) {
      return $this->sendGroupOnly();
    }
    if (!$this->curPoll) {
      return $this->sendNoPoll();
    }

    $this->sendPoll(true, $message['message_id']);
  }

  public function command_results($params, $message) {
    if (!$this->isGroup) {
      return $this->sendGroupOnly();
    }
    if (!$this->curPoll) {
      return $this->sendNoPoll();
    }

    $this->sendResults();
  }

  public function command_endpoll($params, $message) {
    if (!$this->isGroup) {
      return $this->sendGroupOnly();
    }
    if (!$this->curPoll) {
      return $this->sendNoPoll();
    }

    $this->sendResults(true);

    $this->dbDropPoll();
    $this->curPoll = false;
  }

  public function command_done($params, $message) {
    $author_id = $message['from']['id'];
    $newpoll = $this->dbGetPollCreating($author_id);
    if (!$newpoll) {
      $this->sendHelp();
    } else if ($this->curPoll) {
      $this->dbDropPollCreating($author_id);
      $this->sendOnePollOnly();
    } else {
      $this->donePollCreating($author_id, $newpoll, $message['message_id']);
    }
  }

  public function command_help($params, $message) {
    $this->sendHelp();
  }

  public function bot_added_to_chat($message) {
    $this->sendHelp();
  }

  public function some_command($command, $params, $message) {
    $option_num = intval($command);
    if ($option_num > 0) {
      if (!$this->isGroup) {
        return $this->sendGroupOnly();
      }
      if (!$this->curPoll) {
        return $this->sendNoPoll();
      }

      $option_id = $option_num - 1;
      $options_count = count($this->curPoll['options']);
      if ($option_id >= 0 && $option_id < $options_count) {
        $this->pollNewVote($message['from'], $option_id, $message['message_id']);
      }
    } else {
      $this->sendHelp();
    }
  }

  public function message($text, $message) {
    if ($this->curPoll && $this->isGroup) {
      $option = trim($text);
      $option_id = array_search($option, $this->curPoll['options'], true);
      if ($option_id !== false) {
        $this->pollNewVote($message['from'], $option_id, $message['message_id']);
      }
    } else {
      $author_id = $message['from']['id'];
      $newpoll = $this->dbGetPollCreating($author_id);
      if ($newpoll) {
        if ($this->curPoll) {
          $this->dbDropPollCreating($author_id);
          $this->sendOnePollOnly();
          return;
        }
        if ($newpoll['state'] == 'need_title') {
          $title = trim($text);
          $title = str_replace("\n", ' ', $title);
          $title = mb_substr($title, 0, 1024, 'UTF-8');
          if (!strlen($title)) {
            $this->apiSendMessage("Sintoo, só podo incluir texto ou emoji nas preguntas e nas respostas.");
            return;
          }
          $newpoll['title'] = $title;
          $this->needPollOptions($author_id, $newpoll, $message['message_id']);
        } else if ($newpoll['state'] == 'need_options') {
          $option = trim($text);
          $option = str_replace("\n", ' ', $option);
          $option = mb_substr($option, 0, 256, 'UTF-8');
          if (!strlen($option)) {
            $this->apiSendMessage("Sintoo, só podo incluir texto ou emoji nas preguntas e nas respostas.");
            return;
          }
          if (!in_array($option, $newpoll['options'], true)) {
            $newpoll['options'][] = $option;
          }
          if (count($newpoll['options']) < self::$optionsLimit) {
            $this->needPollOptions($author_id, $newpoll, $message['message_id']);
          } else {
            $this->createPoll($author_id, $newpoll);
          }
        }
      }
    }
  }



  protected function parsePollParams($params) {
    $params = explode("\n", $params);
    $params = array_map('trim', $params);
    $params = array_filter($params);
    $params = array_unique($params);

    $title = array_shift($params);
    $title = mb_substr($title, 0, 1024, 'UTF-8');

    $options = array_slice($params, 0, self::$optionsLimit);
    foreach ($options as &$option) {
      $option = mb_substr($option, 0, 256, 'UTF-8');
    }

    return array(
      'title' => $title,
      'options' => $options,
    );
  }

  protected function needPollTitle($author_id, $message_id) {
    $newpoll = array(
      'state' => 'need_title',
    );
    $this->dbSavePollCreating($author_id, $newpoll);

    $text = "Creemos unha nova votación. Primeiro, que qures preguntar?";
    if ($this->isGroup) {
      $params = array(
        'reply_markup' => array(
          'force_reply_keyboard' => true,
          'selective' => true,
        ),
        'reply_to_message_id' => $message_id,
      );
    } else {
      $params = array(
        'reply_markup' => array(
          'hide_keyboard' => true,
        ),
      );
    }
    $this->apiSendMessage($text, $params);
  }

  protected function needPollOptions($author_id, $newpoll, $message_id) {
    if (!isset($newpoll['options'])) {
      $newpoll['options'] = array();
    }
    $newpoll['state'] = 'need_options';
    $this->dbSavePollCreating($author_id, $newpoll);

    if (count($newpoll['options']) > 0) {
      $text = "Ben. Agora dime outra opción de resposta.\n\nCando teñas todas as respostas, indícame /done para publicar a votación.";
    } else {
      $text = "Creando a votación: '{$newpoll['title']}'\n\nPor favor dame a primeira opción de resposta.";
    }
    if ($this->isGroup) {
      $params = array(
        'reply_markup' => array(
          'force_reply_keyboard' => true,
          'selective' => true,
        ),
        'reply_to_message_id' => $message_id,
      );
    } else {
      $params = array(
        'reply_markup' => array(
          'hide_keyboard' => true,
        ),
      );
    }
    $this->apiSendMessage($text, $params);
  }

  protected function donePollCreating($author_id, $newpoll, $message_id = 0) {
    $has_title = strlen($newpoll['title']) > 0;
    $has_options = count($newpoll['options']) > 0;

    if ($has_title && $has_options) {
      $this->createPoll($author_id, $newpoll);
    } else {
      $this->dbDropPollCreating($author_id);
      $this->apiSendMessage("Síntoo, unha votación ten que ter polo menos unha pregunta e unha resposta. Escribe /newpoll para intentalo outra vez.");
    }
  }

  protected function createPoll($author_id, $newpoll) {
    $poll = array(
      'title' => $newpoll['title'],
      'options' => $newpoll['options'],
      'author_id' => $author_id,
    );

    if ($this->isGroup) {
      $this->dbSavePoll($poll);
      $this->curPoll = $poll;
      $poll_id = true;
    } else {
      $this->curPoll = false;
      $poll = $this->dbSavePollById($poll);
      $this->dbDropPollCreating($author_id);
    }

    $this->sendPollCreated($poll);
  }

  protected function pollNewVote($voter, $option_id, $message_id = 0) {
    $chat_id = $this->chatId;
    $voter_id = $voter['id'];

    $message_params = array(
      'reply_markup' => array(
        'hide_keyboard' => true,
        'selective' => true,
      ),
    );
    if ($voter['username']) {
      $name = ' @'.$voter['username'];
    } else {
      $name = $voter['first_name'];
      $message_params['reply_to_message_id'] = $message_id;
    }

    $option = $this->curPoll['options'][$option_id];
    $already_voted = $this->dbCheckOption($voter_id, $option_id);
    if ($already_voted) {
      $text = "☝️{$name} ainda vota '{$option}'.";
    } else {
      $new_vote = $this->dbSelectOption($voter_id, $option_id);
      if ($new_vote) {
        $text = "☝️{$name} votou por '{$option}'.";
      } else {
        $text = "☝️{$name} moudou o seu voto para '{$option}'.";
      }
    }
    $text .= "\n/results - mostra os resultados\n/poll - repite a pregunta";

    $this->apiSendMessage($text, $message_params);
  }



  protected function getPollText($poll, $plain = false) {
    $text = $poll['title']."\n";
    foreach ($poll['options'] as $i => $option) {
      if ($plain) {
        $text .= "\n".($i + 1).". {$option}";
      } else {
        $text .= "\n/".($i + 1).". {$option}";
      }
    }
    return $text;
  }

  protected function getPollKeyboard() {
    $keyboard = array();
    foreach ($this->curPoll['options'] as $option) {
      $keyboard[] = array($option);
    }
    return $keyboard;
  }

  protected function getPollLink($poll_id) {
    $username = strtolower($this->core->botUsername);
    return "telegram.me/{$username}?startgroup={$poll_id}";
  }



  protected function dbGetPoll() {
    $poll_str = $this->redis->get('c'.$this->chatId.':poll');
    if (!$poll_str) {
      return false;
    }
    return json_decode($poll_str, true);
  }

  protected function dbSavePoll($poll) {
    $poll_str = json_encode($poll);
    $this->redis->set('c'.$this->chatId.':poll', $poll_str);
  }

  protected function dbGetPollById($poll_id) {
    $poll_str = $this->redis->get('poll:'.$poll_id);
    if (!$poll_str) {
      return false;
    }
    return json_decode($poll_str, true);
  }

  protected function dbSavePollById($poll) {
    $poll_str = json_encode($poll);
    $tries = 0;
    do {
      $poll_id = md5($poll_str.'#'.$tries);
      $result = $this->redis->setnx('poll:'.$poll_id, $poll_str);
      if ($result) {
        break;
      }
    } while (++$tries < 100);

    $poll['id'] = $poll_id;
    return $poll;
  }

  protected function dbDropPoll() {
    $keys = array(
      'c'.$this->chatId.':poll',
      'c'.$this->chatId.':members',
    );
    for ($i = 0; $i < self::$optionsLimit; $i++) {
      $keys[] = 'c'.$this->chatId.':o'.$i.':members';
    }
    $this->redis->del($keys);
  }

  protected function dbCheckOption($voter_id, $option_id) {
    $chat_id = $this->chatId;
    return $this->redis->sIsMember('c'.$chat_id.':o'.$option_id.':members', $voter_id);
  }

  protected function dbSavePollCreating($author_id, $poll) {
    $chat_id = $this->chatId;
    $this->redis->set("newpoll{$chat_id}:{$author_id}", json_encode($poll));
  }

  protected function dbGetPollCreating($author_id) {
    $chat_id = $this->chatId;
    $poll = json_decode($this->redis->get("newpoll{$chat_id}:{$author_id}"), true);
    return $poll;
  }

  protected function dbDropPollCreating($author_id) {
    $chat_id = $this->chatId;
    return $this->redis->del("newpoll{$chat_id}:{$author_id}");
  }

  protected function dbSelectOption($voter_id, $option_id) {
    $chat_id = $this->chatId;
    $redis = $this->redis->multi();
    $this->redis->sadd('c'.$chat_id.':members', $voter_id); 

    $options_count = count($this->curPoll['options']);
    for ($i = 0; $i < $options_count; $i++) {
      if ($i == $option_id) {
        $this->redis->sadd('c'.$chat_id.':o'.$i.':members', $voter_id);
      } else {
        $this->redis->srem('c'.$chat_id.':o'.$i.':members', $voter_id);
      }
    }
    $result = $this->redis->exec();
    $added = array_shift($result);
    return $added;
  }



  protected function sendGreeting() {
    $this->apiSendMessage("Para crear unha votación, envíame unha mensaxe exactamente con este formato:\n\n/newpoll\nA túa pregunta\nOpción de resposta 1\nOpción de resposta 2\n...\nOpción de resposta x");
  }

  protected function sendGroupOnly() {
    $this->apiSendMessage("Este comando só funcionará naqueles grupos que teñan unha votación activa. Usa /newpoll para crear unha.");
  }

  protected function sendNoPoll() {
    $this->apiSendMessage("Non hai votacións activas neste grupo. Usa /newpoll para crear unha primeiro.");
  }

  protected function sendOnePollOnly() {
    $this->apiSendMessage("Síntoo, só unha votación de cada vez.\n/poll - repite a pregunta\n/endpoll - pecha a votación");
  }

  protected function sendHelp() {
    if ($this->isGroup) {
      $text = "Este bot pode crear votacións nos grupos.";
    } else {
      $text = "Este bot pode crear votacións sinxelas. Podes crear aquí unha votación e compartila cun grupo.";
    }
    $text .= "\n\n/newpoll - nova votación\n/results - resultados parciais da votación\n/poll - repite a pregunta\n/endpoll - pecha a votación e mostra os resultados";
    $this->apiSendMessage($text);
  }

  public function sendPoll($resend = false, $message_id = 0) {
    $text = $this->getPollText($this->curPoll);
    if ($this->isGroup) {
      $text .= "\n\n/results - mostra os resultados\n/endpoll - pecha a votación";
    }
    $message_params = array(
      'reply_markup' => array(
        'keyboard' => $this->getPollKeyboard(),
      ),
    );
    if ($resend && $this->isGroup) {
      $options['reply_markup']['selective'] = true;
      $options['reply_to_message_id'] = $message_id;
    }
    $this->apiSendMessage($text, $message_params);
  }

  protected function sendPollCreated($poll) {
    $text = "👍 Poll created.";
    if (!$this->isGroup) {
      $text .= " Usa esta ligazón para compartir esta votación nun grupo:\n";
      $text .= $this->getPollLink($poll['id']);
      $text .= "\n\n";
      $text .= $this->getPollText($poll, true);
    }
    $this->apiSendMessage($text);

    if ($this->isGroup) {
      $this->sendPoll();
    }
  }

  protected function sendResults($final = false) {
    $results = array();
    $total_value = 0;
    $max_value = 0;
    foreach ($this->curPoll['options'] as $i => $option) {
      $value = intval($this->redis->sCard('c'.$this->chatId.':o'.$i.':members'));
      $total_value += $value;
      $max_value = max($max_value, $value);
      $results[] = array(
        'label' => $option,
        'value' => $value,
      );
    }
    foreach ($results as &$result) {
      $result['pc'] = $max_value ? round($result['value'] * 7 / $max_value) : 0;
      $result['procent'] = $total_value ? round($result['value'] * 100 / $total_value) : 0;
    }
    uasort($results, function($a, $b) { return ($b['value'] - $a['value']); });

    $text = '';
    if ($final) {
      $text .= "📊 Votación pechada, resultados:\n\n";
    }
    $text .= $this->curPoll['title']."\n";
    if (!$total_value) {
      $text .= "👥 Ninguén";
    } else if ($total_value == 1) {
      $text .= "👥 1 persoa";
    } else {
      $text .= "👥 {$total_value} persoas";
    }
    if ($final) {
      $text .= " votaron en total.";
    } else {
      $text .= " votaron ata agora.";
    }
    foreach ($results as &$result) {
      $text .= "\n\n{$result['label']} – {$result['value']}\n";
      $text .= ($result['pc'] ? str_repeat('👍', $result['pc']) : '▫️');
      $text .= " {$result['procent']}%";
    }
    if (!$final) {
      $text .= "\n\n/poll - repite a pregunta\n/endpoll - pecha a votación";
    }

    $message_params = array();
    if ($final) {
      $message_params['reply_markup'] = array(
        'hide_keyboard' => true,
      );
    }

    $this->apiSendMessage($text, $message_params);
  }
}
