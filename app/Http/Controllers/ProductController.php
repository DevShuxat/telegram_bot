<?php

namespace App\Http\Controllers;

use App\Exports\DynamicExport;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use Exception;
use PDO;

class ProductController extends Controller
{
    protected array $conversationState = [];
    protected array $userData = [];  // Foydalanuvchi ma'lumotlarini va bazalarni saqlash uchun

    /**
     * @throws Exception
     */
    public function handleWebhook(Request $request): JsonResponse
    {
        $update = $request->all();
//        dd($update);

        $chatId = $update['message']['chat']['id'];
        $message = $update['message']['text'] ?? '';
        $document = $update['message']['document'] ?? null;

        if ($message === '/start') {
            $responseText = "Botga xush kelibsiz! Quyidagi buyruqlardan foydalaning:\n";
            $responseText .= "/select_connections - Ulanishni tanlash\n";
            $responseText .= "/databases - Mavjud ma'lumotlar bazalarini ko'rish\n";
            $responseText .= "/sql - SQL so'rovini yuborish";
            $this->sendMessage($chatId, $responseText);
        } elseif ($message === '/select_connections') {
            $this->conversationState[$chatId] = 'awaiting_ssh_connection_type';
            $this->sendMessage($chatId, 'SSH ulanish turini tanlang: "oddiy" yoki "sertifikat"');
        } elseif ($message === '/databases') {
            $this->sendDatabasesList($chatId);
        } elseif ($message === '/sql') {
            $this->conversationState[$chatId] = 'awaiting_sql_query';
            $this->sendMessage($chatId, 'SQL so\'rovini kiriting (faqat SELECT):');
        } elseif ($document && $this->conversationState[$chatId] === 'awaiting_ssh_certificate') {
            $this->handleSshCertificate($chatId, $document);
        } else {
            $this->handleUserInput($chatId, $message);
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * @throws Exception
     */
    public function handleUserInput($chatId, $message): void
    {
        switch ($this->conversationState[$chatId] ?? null) {
            case 'awaiting_ssh_connection_type':
                if ($message === 'oddiy') {
                    $this->conversationState[$chatId] = 'awaiting_ssh_host';
                    $this->sendMessage($chatId, 'SSH hostni kiriting:');
                } elseif ($message === 'sertifikat') {
                    $this->conversationState[$chatId] = 'awaiting_ssh_host';
                    $this->userData[$chatId]['ssh_type'] = 'certificate';
                    $this->sendMessage($chatId, 'SSH hostni kiriting:');
                } else {
                    $this->sendMessage($chatId, 'Iltimos, "oddiy" yoki "sertifikat" deb kiriting.');
                }
                break;
            case 'awaiting_ssh_host':
                $this->userData[$chatId]['current_connection']['ssh_host'] = $message;
                $this->conversationState[$chatId] = 'awaiting_ssh_port';
                $this->sendMessage($chatId, 'SSH portni kiriting:');
                break;
            case 'awaiting_ssh_port':
                $this->userData[$chatId]['current_connection']['ssh_port'] = $message;
                $this->conversationState[$chatId] = 'awaiting_ssh_username';
                $this->sendMessage($chatId, 'SSH usernameni kiriting:');
                break;
            case 'awaiting_ssh_username':
                $this->userData[$chatId]['current_connection']['ssh_username'] = $message;
                if ($this->userData[$chatId]['ssh_type'] === 'certificate') {
                    $this->conversationState[$chatId] = 'awaiting_ssh_certificate';
                    $this->sendMessage($chatId, 'SSH sertifikat faylini yuboring:');
                } else {
                    $this->conversationState[$chatId] = 'awaiting_ssh_password';
                    $this->sendMessage($chatId, 'SSH passwordni kiriting:');
                }
                break;
            case 'awaiting_ssh_password':
                $this->userData[$chatId]['current_connection']['ssh_password'] = $message;
                $this->conversationState[$chatId] = 'awaiting_db_host';
                $this->sendMessage($chatId, 'Database hostni kiriting:');
                break;
            case 'awaiting_db_host':
                $this->userData[$chatId]['current_connection']['db_host'] = $message;
                $this->conversationState[$chatId] = 'awaiting_db_port';
                $this->sendMessage($chatId, 'Database portni kiriting:');
                break;
            case 'awaiting_db_port':
                $this->userData[$chatId]['current_connection']['db_port'] = $message;
                $this->conversationState[$chatId] = 'awaiting_database';
                $this->sendMessage($chatId, 'Database nomini kiriting:');
                break;
            case 'awaiting_database':
                $this->userData[$chatId]['current_connection']['database'] = $message;
                $this->conversationState[$chatId] = 'awaiting_db_username';
                $this->sendMessage($chatId, 'Database usernameni kiriting:');
                break;
            case 'awaiting_db_username':
                $this->userData[$chatId]['current_connection']['db_username'] = $message;
                $this->conversationState[$chatId] = 'awaiting_db_password';
                $this->sendMessage($chatId, 'Database passwordni kiriting:');
                break;
            case 'awaiting_db_password':
                $this->userData[$chatId]['current_connection']['db_password'] = $message;
                $this->testDatabaseConnection($chatId);
                break;
            case 'awaiting_sql_query':
                $this->executeSqlQuery($chatId, $message);
                break;
            default:
                $this->sendMessage($chatId, 'Noma\'lum buyruq yoki holat.');
        }
    }

    /**
     * @throws Exception
     */
    private function handleSshCertificate($chatId, $document): void
    {
        $token = env('TELEGRAPH_BOT_TOKEN');
        $fileId = $document['file_id'];

        $url = "https://api.telegram.org/bot$token/getFile?file_id=$fileId";
        $response = json_decode(file_get_contents($url), true);

        $filePath = $response['result']['file_path'];
        $fileUrl = "https://api.telegram.org/file/bot$token/$filePath";

        $certificatePath = storage_path('app/ssh_certificates/' . basename($filePath));
        file_put_contents($certificatePath, file_get_contents($fileUrl));

        $this->userData[$chatId]['current_connection']['ssh_certificate'] = $certificatePath;
        $this->conversationState[$chatId] = 'awaiting_db_host';
        $this->sendMessage($chatId, 'SSH sertifikat saqlandi. Endi database hostni kiriting:');
    }

    /**
     * @throws Exception
     */
    private function testDatabaseConnection($chatId): void
    {
        $config = $this->userData[$chatId]['current_connection'];

        $connection = [
            'driver' => 'mysql',
            'host' => $config['db_host'],
            'port' => $config['db_port'],
            'database' => $config['database'],
            'username' => $config['db_username'],
            'password' => $config['db_password'],
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'options' => [],
        ];

        if (isset($config['ssh_certificate'])) {
            $connection['options'][PDO::MYSQL_ATTR_SSL_KEY] = $config['ssh_certificate'];
        }

        try {
            DB::purge('dynamic');
            config(['database.connections.dynamic' => $connection]);
            DB::connection('dynamic')->getPdo();

            $this->sendMessage($chatId, 'Database is connected!');

            // Ulanish muvaffaqiyatli bo'lsa, ma'lumotlarni saqlash
            $this->saveDatabaseConnection($chatId);
        } catch (Exception $e) {
            $this->sendMessage($chatId, 'Ma\'lumotlarni tekshirib ko\'ring: ' . $e->getMessage());
        } finally {
            unset($this->conversationState[$chatId]);  // Tozalash
            unset($this->userData[$chatId]['current_connection']);  // Tozalash
        }
    }

    /**
     * @throws Exception
     */
    private function saveDatabaseConnection($chatId): void
    {
        if (!isset($this->userData[$chatId]['databases'])) {
            $this->userData[$chatId]['databases'] = [];
        }

        // Ma'lumotlar bazasi ulanishini ro'yxatga qo'shish
        $this->userData[$chatId]['databases'][] = $this->userData[$chatId]['current_connection']['database'];

        $this->sendMessage($chatId, 'Ma\'lumotlar bazasi saqlandi.');
    }

    /**
     * @throws Exception
     */
    private function sendDatabasesList($chatId): void
    {
        $databases = $this->userData[$chatId]['databases'] ?? [];

        if (empty($databases)) {
            $this->sendMessage($chatId, 'Hech qanday ma\'lumotlar bazasi yo\'q.');
            return;
        }

        $keyboard = array_map(function ($database) {
            return [['text' => $database]];
        }, $databases);

        $replyMarkup = json_encode(['keyboard' => $keyboard, 'resize_keyboard' => true, 'one_time_keyboard' => true]);

        $this->sendMessageWithMarkup($chatId, 'Quyidagi ma\'lumotlar bazalarini tanlang:', $replyMarkup);
    }

    private function executeSqlQuery($chatId, $query): void
    {
        if (stripos($query, 'select') !== 0) {
            $this->sendMessage($chatId, 'Faqat SELECT so\'rovlari bajarilishi mumkin.');
            return;
        }

        try {
            $results = DB::connection('dynamic')->select(DB::raw($query));

            if (empty($results)) {
                $this->sendMessage($chatId, 'Hech qanday natija topilmadi.');
                return;
            }

            // Natijalarni eksport qilish va yuklab olish tugmalarini yuborish
            $filePathCsv = $this->exportToCsv($results);
            $filePathExcel = $this->exportToExcel($results);

            $keyboard = [
                [
                    ['text' => 'CSV yuklab olish', 'url' => $filePathCsv],
                    ['text' => 'Excel yuklab olish', 'url' => $filePathExcel],
                ],
            ];

            $replyMarkup = json_encode(['inline_keyboard' => $keyboard]);

            $this->sendMessageWithMarkup($chatId, 'Natijalarni yuklab olish uchun tanlang:', $replyMarkup);
        } catch (Exception $e) {
            $this->sendMessage($chatId, 'So\'rovda xatolik: ' . $e->getMessage());
        }
    }

    private function exportToCsv($results): \Illuminate\Foundation\Application|string|UrlGenerator|Application
    {
        $filename = 'results.csv';
        $handle = fopen($filename, 'w+');
        fputcsv($handle, array_keys((array)$results[0]));

        foreach ($results as $row) {
            fputcsv($handle, (array)$row);
        }

        fclose($handle);

        return url($filename);
    }

    private function exportToExcel($results): \Illuminate\Foundation\Application|string|UrlGenerator|Application
    {
        $filename = 'results.xlsx';

        Excel::store(new DynamicExport($results), $filename);

        return url($filename);
    }

    /**
     * @throws Exception
     */
    private function sendMessage($chatId, $text): void
    {
        $token = env('TELEGRAPH_BOT_TOKEN');
        $url = "https://api.telegram.org/bot$token/sendMessage";

        $data = [
            'chat_id' => $chatId,
            'text' => $text,
        ];

        $this->makeRequest($url, $data);
    }

    /**
     * @throws Exception
     */
    private function sendMessageWithMarkup($chatId, $text, $replyMarkup): void
    {
        $token = env('TELEGRAPH_BOT_TOKEN');
        $url = "https://api.telegram.org/bot$token/sendMessage";

        $data = [
            'chat_id' => $chatId,
            'text' => $text,
            'reply_markup' => $replyMarkup,
        ];

        $this->makeRequest($url, $data);
    }

    /**
     * @throws Exception
     */
    private function makeRequest($url, $data): void
    {
        $options = [
            'http' => [
                'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
                'method'  => 'POST',
                'content' => http_build_query($data),
            ],
        ];

        $context = stream_context_create($options);
        $result = file_get_contents($url, false, $context);

        if ($result === FALSE) {
            throw new Exception('Telegramga xabar yuborishda xatolik yuz berdi');
        }

        json_decode($result);
    }
}
