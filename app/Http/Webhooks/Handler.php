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
use Maatwebsite\Excel\Facades\Excel;
use PDO;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class Handler extends WebhookHandler
{

    private array $connections = [
        'sertifikat_db' => [
            'ssh_host' => '185.8.212.167',
            'ssh_port' => '22',
            'ssh_username' => 'sertifikat-api',
            'ssh_password' => 'TAL7_LnAnJ7Lds3a',
            'db_host' => '127.0.0.1',
            'db_port' => '6432',
            'database' => 'sertifikat_db',
            'db_username' => 'sertifikat_user',
            'db_password' => 'KMr3HL_3xraR',
        ],
    ];

    public function start(): void
    {
        $this->reply("Botga xush kelibsiz! Quyidagi buyruqlardan foydalaning:\n/select_connections - Ulanishni tanlash\n/sql - SQL so'rovini yuborish");
    }

    public function select_connections(): void
    {
        $keyboard = Keyboard::make();
        foreach (array_keys($this->connections) as $connectionName) {
            $keyboard->buttons([
                Button::make($connectionName)->action('setConnection')->param('name', $connectionName),
            ]);
        }

        $this->chat->message('Ulanishni tanlang:')
            ->keyboard($keyboard)
            ->send();
    }

    public function setConnection(): void
    {
        $connectionName = $this->data->get('name');
        if (isset($this->connections[$connectionName])) {
            Cache::put('selected_connection_' . $this->chat->id, $connectionName, 3600);
            $this->reply("$connectionName ulanishi tanlandi. Endi /sql buyrug'i orqali so'rov yuborishingiz mumkin.");
        } else {
            $this->reply("Noto'g'ri ulanish tanlandi.");
        }
    }

    public function sql(): void
    {
        $connectionName = Cache::get('selected_connection_' . $this->chat->id);
        if (!$connectionName) {
            $this->reply("Iltimos, avval /select_connection buyrug'i orqali ulanishni tanlang.");
            return;
        }

        $connection = $this->connections[$connectionName];

        try {
            $this->connectionDB($connection);
        } catch (Exception $e) {
            $this->reply("Ulanishda xatolik yuz berdi: " . $e->getMessage());
            return;
        }

        $query = trim(str_replace('/sql', '', $this->message->text()));


        if (empty($query)) {
            $this->reply("SQL so'rovini kiriting. Masalan: /sql SELECT * FROM users WHERE id = 1");
        }

        try {
            $pdo = DB::connection('dynamic_pgsql')->getPdo();
            $statement = $pdo->prepare($query);
            $statement->execute();
            $result = $statement->fetchAll(PDO::FETCH_ASSOC);

            if (empty($result)) {
                $this->chat->message('Hech qanday ma\'lumot topilmadi.')->send();
                return;
            }

            $this->storeQueryResult($result);
            $this->chat->message('Natija topildi. Qaysi formatda faylni jo\'natish kerakligini tanlang:')
                ->keyboard(
                    Keyboard::make()
                        ->buttons([
                            Button::make('Excel')->action('sendFile')->param('format', 'excel'),
                            Button::make('CSV')->action('sendFile')->param('format', 'csv')
                        ])
                )
                ->send();
        } catch (Exception $e) {
            $this->chat->message('SQL so\'rovini bajarishda xato: ' . $e->getMessage())->send();
        }
    }

    protected function connectionDB($connection): void
    {
        try {
            Log::info('Ulanish ma\'lumotlari: ' . json_encode($connection, JSON_UNESCAPED_UNICODE));

            $sshCommand = "ssh -o StrictHostKeyChecking=no -f -L {$connection['db_port']}:localhost:5432 {$connection['ssh_username']}@{$connection['ssh_host']} -N";

            $process = Process::fromShellCommandline($sshCommand);
            $process->setTimeout(null);
            $process->run();

            if (!$process->isSuccessful()) {
                throw new ProcessFailedException($process);
            }

            Log::info('SSH tunneling muvaffaqiyatli o\'rnatildi.');

            $pdo = new PDO(
                "pgsql:host={$connection['db_host']};port={$connection['db_port']};dbname={$connection['database']}",
                $connection['db_username'],
                $connection['db_password']
            );

            Config::set('database.connections.dynamic_pgsql', [
                'driver' => 'pgsql',
                'host' => $connection['db_host'],
                'port' => $connection['db_port'],
                'database' => $connection['database'],
                'username' => $connection['db_username'],
                'password' => $connection['db_password'],
                'charset' => 'utf8',
                'prefix' => '',
                'schema' => 'public',
                'sslmode' => 'prefer',
            ]);

            DB::purge('dynamic_pgsql');
            DB::connection('dynamic_pgsql')->setPdo($pdo);

            Log::info('Ulanish muvaffaqiyatli o\'rnatildi.');
        } catch (Exception $e) {
            Log::error('Ulanish xatosi: ' . $e->getMessage());
            Log::error('Xato tafsilotlari: ' . $e->getTraceAsString());
            throw new Exception('Baza bilan ulanishni amalga oshirishda xatolik yuz berdi: ' . $e->getMessage());
        }
    }



    public function sql1(): void
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
        Cache::put('query_result_' . $this->chat->id, $result, 300);
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
        $this->chat->message('Tanla')->keyboard(
            Keyboard::make()->buttons([
                Button::make('Saytga otish')->url('https://xorijdaish.uz'),
                Button::make('Qiziqish bildirish')->action('like'),
                Button::make('Obuna bo\'lish')
                    ->action('subscribe')
                    ->param('channel_name', '@xorijdaish'),
            ])
        )->send();

//        $this->chat->message('Tanla')
//            ->keyboard(function(Keyboard $keyboard){
//                return $keyboard
//                    ->button('Qiziqish bildirish')->action('like')
//                    ->button('Saytga otish')->url('https://xorijdaish.uz')
//                    ->button('Obuna bo\'lish')->action('subscribe')->param('channel_name','@xorijdaish');
//            })->send();
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
        $this->chat->message('Siz qiziqish bildirgan vakansiya raqamini tanlang')->send();
    }

    public function subscribe(): void
    {
        $this->reply("Rahmat sizga obuna uchun {$this->data->get('channel_name')}");
    }

    protected function handleUnknownCommand($text): void
    {
        if ($text === '/start') {
            $this->start();
        } else {
            $this->reply('Noma\'lum buyruq. Yordam uchun /start ni yuboring.');
        }
    }
}
