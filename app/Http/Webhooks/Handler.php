<?php

namespace App\Http\Webhooks;

use App\Exports\ResultsExport;
use DefStudio\Telegraph\Facades\Telegraph;
use DefStudio\Telegraph\Handlers\WebhookHandler;
use DefStudio\Telegraph\Keyboard\Button;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use DefStudio\Telegraph\Keyboard\Keyboard;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Stringable;
use Maatwebsite\Excel\Facades\Excel;
use PDO;
use Symfony\Component\Process\Process;

class Handler extends WebhookHandler
{
    public function connectionDB($connection): void
    {
        try {
            Log::info('Ulanish ma\'lumotlari: ' . json_encode($connection, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            // SSH tunneling sozlash
            $sshProcess = new Process([
                'ssh', '-o', 'StrictHostKeyChecking=no', '-f', '-L',
                "6432:localhost:5432", // Local port forwarding
                "{$connection['ssh_username']}@{$connection['ssh_host']}", // Remote SSH login
                '-N'
            ]);

            $sshProcess->run();

            if (!$sshProcess->isSuccessful()) {
                throw new Exception('SSH tunneling xatosi: ' . $sshProcess->getErrorOutput());
            }

            Log::info('SSH tunneling muvaffaqiyatli o\'rnatildi.');

            $arr = [
                'driver' => 'pgsql',
                'host' => '127.0.0.1', // Local host for SSH tunnel
                'port' => '6432', // Local port for SSH tunnel
                'database' => $connection['database'],
                'username' => $connection['db_username'],
                'password' => $connection['db_password'],
                'charset' => 'utf8',
                'prefix' => '',
                'schema' => 'public',
                'sslmode' => 'prefer',
            ];

            $pdo = new PDO(
                "pgsql:host={$arr['host']};port={$arr['port']};dbname={$arr['database']}",
                $arr['username'],
                $arr['password']
            );

            Config::set('database.connections.dynamic_pgsql', $arr);

            DB::purge('dynamic_pgsql');
            DB::connection('dynamic_pgsql')->setPdo($pdo);

            Log::info('Ulanish muvaffaqiyatli o\'rnatildi.');
        } catch (Exception $e) {
            Log::error('Ulanish xatosi: ' . $e->getMessage());
            $this->chat->message('Baza bilan ulanishni amalga oshirishda xatolik yuz berdi: ' . $e->getMessage())->send();
        }
    }

    public function sql(): void
    {
        $sqlCommand = $this->data->get('text');
        $sqlCommand = str_replace('/sql', '', $sqlCommand);

        // Bo'sh joylardan tozalash
        $sqlCommand = trim($sqlCommand);

        Log::info('Kelgan SQL buyruq: ' . $sqlCommand);

        $commandParts = explode('?', $sqlCommand);

        // Agar biror qism bo'sh yoki noto'g'ri bo'lsa, foydalanuvchiga xato haqida xabar berish
        if (count($commandParts) < 10 || empty(trim($commandParts[0])) || empty(trim($commandParts[9]))) {
            $this->chat->message('SQL buyrug\'ini to\'g\'ri kiritmadingiz. Format: /sql ssh_host?ssh_port?ssh_username?ssh_password?db_host?db_port?database?db_username?db_password?sql_query')->send();
            return;
        }

        $config = [
            'ssh_host' => trim($commandParts[0]),
            'ssh_port' => trim($commandParts[1]),
            'ssh_username' => trim($commandParts[2]),
            'ssh_password' => trim($commandParts[3]),
            'db_host' => trim($commandParts[4]),
            'db_port' => trim($commandParts[5]),
            'database' => trim($commandParts[6]),
            'db_username' => trim($commandParts[7]),
            'db_password' => trim($commandParts[8]),
        ];

        Log::info('config message: ' . json_encode($config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $query = trim($commandParts[9]);

        $this->connectionDB($config);

        try {
            $result = DB::connection('dynamic_pgsql')->select($query);

            if (empty($result)) {
                $this->chat->message('Hech qanday ma\'lumot topilmadi.')->send();
                return;
            }

            $this->chat->message('Qaysi formatda faylni jo\'natish kerakligini tanlang:')
                ->keyboard(
                    Keyboard::make()
                        ->buttons([
                            Button::make('Excel')->action('sendFile')->param('format', 'excel'),
                            Button::make('CSV')->action('sendFile')->param('format', 'csv')
                        ])
                )
                ->send();

            $this->storeQueryResult($result);
        } catch (Exception $e) {
            $this->chat->message('SQL buyrug\'ini bajarishda xato: ' . $e->getMessage())->send();
        }
    }

    protected function storeQueryResult($result): void
    {
        Cache::put('query_result', $result, 300);
    }

    public function sendFile(string $format): void
    {
        $result = Cache::get('query_result');

        if (!$result) {
            $this->chat->message('Natija topilmadi yoki eskirgan. Iltimos, yangidan so\'rov yuboring.')->send();
            return;
        }

        $fileName = 'sql_results_' . time(); // Ensure this is a string

        Log::info('FileName type: ' . gettype($fileName));

        if ($format === 'excel') {
            $fileName .= '.xlsx';
            Excel::store(new ResultsExport($result), $fileName, 'local');
            $filePath = storage_path('app/' . $fileName);
            Log::info('FilePath type: ' . gettype($filePath));
        } elseif ($format === 'csv') {
            $fileName .= '.csv';
            $filePath = storage_path('app/' . $fileName);
            Log::info('FilePath type: ' . gettype($filePath));
            $file = fopen($filePath, 'w');

            if (!empty($result)) {
                $columns = array_keys((array)$result[0]);
                fputcsv($file, $columns);
            }

            foreach ($result as $row) {
                fputcsv($file, (array)$row);
            }

            fclose($file);
        } else {
            $this->chat->message('Noto\'g\'ri format tanlandi.')->send();
            return;
        }

        if (File::exists($filePath)) {
            $this->chat->document($filePath)->reply($this->messageId)->send();

            // Ensure $fileName is a string
            Log::info(ucfirst($format) . " fayli jo'natildi: " . $fileName);
            Storage::delete($fileName); // Faylni o'chirish
        } else {
            Log::error(ucfirst($format) . " fayl yaratishda muammo yuz berdi.");
        }
    }

    public function actions(): void
    {
        Telegraph::message('Tanla')->keyboard(
            Keyboard::make()->buttons([
                Button::make('Saytga otish')->url('https://xorijdaish.uz'),
                Button::make('Qiziqish bildirish')->action('like'),
                Button::make('Obuna bo\'lish')
                    ->action('subscribe')
                    ->param('channel_name', '@xorijdaish'),
            ])
        )->send();
    }

    public function logs(): void
    {
        Log::info('Logs method called');

        $logPath = storage_path('logs');
        $logFiles = File::files($logPath);

        Log::info('Log files found: ' . count($logFiles));

        if (empty($logFiles)) {
            Log::info('No log files found');
            $this->chat->message('Log fayllari topilmadi.')->reply($this->messageId)->send();
            return;
        }

        $keyboard = Keyboard::make();
        foreach ($logFiles as $file) {
            $filename = $file->getFilename();
            Log::info('Adding button for file: ' . $filename);

            // Tugma yaratish uchun action yoki param metodlaridan foydalanamiz
            $keyboard->buttons([
                Button::make($filename)->action('sendLog')->param('filename', $filename),
            ]);
        }

        Log::info('Sending message with keyboard');
        $this->chat->message('Qaysi log faylni yuklashni xohlaysiz?')
            ->keyboard($keyboard)
            ->send();

        Log::info('Logs method completed');
    }

    public function sendLog()
    {
        $callbackData = $this->data;

        Log::info('Complete callback data: ' . json_encode($callbackData, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $filename = $callbackData['filename'] ?? null;

        if (!$filename) {
            $this->chat->message('Fayl nomi ko\'rsatilmagan.')->reply($this->messageId)->send();
            return;
        }

        $filePath = storage_path("logs/{$filename}");

        if (!File::exists($filePath)) {
            $this->chat->message('Kechirasiz, fayl topilmadi.')->reply($this->messageId)->send();
            return;
        }

        $this->chat->document($filePath)->reply($this->messageId)->send();
    }

    public function like(): void
    {
        Telegraph::message('Siz qiziqish bildirgan vakansiya raqamini tanlang')->send();
    }

    public function subscribe(): void
    {
        $this->reply("Rahmat sizga obuna uchun {$this->data->get('channel_name')}");
    }

    protected function handleUnknownCommand(Stringable $text): void
    {
        if ($text->value() === '/start') {
            $this->reply('Botga hush kelibsiz');
        } else {
            $this->reply('Nomalum kommanda');
        }
    }
}
