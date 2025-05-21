<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Telegram\Bot\Api;
use Telegram\Bot\Exceptions\TelegramSDKException;
use Exception;
use Illuminate\Support\Facades\Log;
use Google\Client;
use Google\Service\Books;

class TelegramPoll extends Command
{
    protected $signature = 'telegram:poll';
    protected $description = 'Запуск Telegram бота с интеграцией Google Books API';
    protected $lastUpdateId = 0;
    protected $googleBooks;

    public function __construct()
    {
        parent::__construct();
        $this->initializeGoogleBooks();
    }

    protected function initializeGoogleBooks()
    {
        try {
            $client = new Client();
            $client->setApplicationName('Telegram Book Bot');
            $this->googleBooks = new Books($client);
            Log::info('Google Books API initialized');
        } catch (Exception $e) {
            Log::error('Google Books API init failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    public function handle()
    {
        try {
            $telegram = new Api(env('TELEGRAM_BOT_TOKEN'));
            $this->info('Бот с Google Books API запущен. Для остановки Ctrl + C');
            Log::info('Bot with Google Books API started');

            while (true) {
                $this->pollUpdates($telegram);
            }

        } catch (Exception $e) {
            Log::error('Bot critical error', ['error' => $e->getMessage()]);
            $this->error('Ошибка: ' . $e->getMessage());
            sleep(10);
            $this->handle();
        }
    }

    protected function pollUpdates(Api $telegram)
    {
        try {
            $updates = $telegram->getUpdates([
                'offset' => $this->lastUpdateId + 1,
                'timeout' => 30
            ]);

            foreach ($updates as $update) {
                $this->lastUpdateId = $update->update_id;
                $this->handleUpdate($telegram, $update);
            }

            sleep(1);

        } catch (Exception $e) {
            Log::error('Polling error', ['error' => $e->getMessage()]);
            sleep(5);
        }
    }

    protected function handleUpdate(Api $telegram, $update)
    {
        if ($update->getMessage()) {
            $message = $update->getMessage();
            $chatId = $message->getChat()->getId();
            $text = trim($message->getText() ?? '');

            Log::info('Received message', ['chat_id' => $chatId, 'text' => $text]);

            try {
                if (str_starts_with($text, '/search ')) {
                    $query = substr($text, 8);
                    $this->handleBookSearch($telegram, $chatId, $query);
                } else {
                    $this->handleDefaultCommands($telegram, $chatId, $text);
                }
            } catch (Exception $e) {
                Log::error('Update handling failed', [
                    'chat_id' => $chatId,
                    'error' => $e->getMessage()
                ]);
                $telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => 'Произошла ошибка при обработке запроса!'
                ]);
            }
        }
    }

    protected function handleBookSearch(Api $telegram, $chatId, $query)
    {
        if (empty($query)) {
            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'Введите поисковый запрос после /search'
            ]);
            return;
        }

        Log::info('Book search requested', ['query' => $query]);

        try {
            $results = $this->googleBooks->volumes->listVolumes($query, [
                'maxResults' => 5,
                'printType' => 'BOOKS'
            ]);

            if (empty($results->getItems())) {
                $telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => "По запросу \"{$query}\" книг не найдено"
                ]);
                return;
            }

            $response = "Результаты поиска:\n\n";
                foreach ($results->getItems() as $item) {
                $volume = $item->getVolumeInfo();
                $title = htmlspecialchars($volume->getTitle() ?? 'Без названия');
                $authors = htmlspecialchars(implode(', ', $volume->getAuthors() ?? ['Неизвестен']));
    
                $response .= "Название: <i>{$title}</i>\n";
                $response .= "Авторство: <i>{$authors}</i>\n\n";
            }

            $telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => $response,
            'parse_mode' => 'HTML'
            ]);

            Log::debug('Book search results', ['query' => $query, 'count' => count($results->getItems())]);

        } catch (Exception $e) {
            Log::error('Book search failed', [
                'query' => $query,
                'error' => $e->getMessage()
            ]);
            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'Ошибка при поиске книг!'
            ]);
        }
    }

    protected function handleDefaultCommands(Api $telegram, $chatId, $text)
    {
        switch ($text) {
            case '/start':
                $telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => "Бот предназначен для поиска книг\n\n"
                            . "Используйте /search [запрос] для поиска книг\n"
                            . "Например: /search Гарри Поттер"
                ]);
                break;
                
            case '/help':
                $telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => "Доступные команды:\n\n"
                            . "/search [текст] - найти книги\n"
                            . "/help - справка"
                ]);
                break;
                
            default:
                $telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => "Используйте /search для поиска книг или /help - для справки"
                ]);
        }
    }
}