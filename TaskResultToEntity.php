<?php
require_once 'crest.php';

// Настройка логирования
function logToFile($data)
{
    $logFile = __DIR__ . '/task_result_to_entity.txt';
    $current = file_get_contents($logFile);
    $current .= date('Y-m-d H:i:s') . " - " . print_r($data, true) . "\n";
    file_put_contents($logFile, $current);
}

// Получение данных из POST-запроса
$input = file_get_contents('php://input');
$data = json_decode($input, true);

// Если JSON декодирование не сработало, пробуем parse_str для обратной совместимости
if ($data === null) {
    parse_str($input, $data);
}

// Проверка обязательных полей
if (
    !isset($data['auth']['access_token']) || !isset($data['auth']['domain']) ||
    !isset($data['properties']['task_id']) || !isset($data['properties']['entity_type']) ||
    !isset($data['properties']['entity_id']) || !isset($data['properties']['field_code'])
) {
    logToFile('Ошибка: Не хватает обязательных полей в запросе');
    http_response_code(400);
    echo json_encode(['error' => 'Требуемые поля: access_token, domain, task_id, entity_type, entity_id, field_code']);
    exit;
}

// Параметры запроса
$access_token = $data['auth']['access_token'];
$domain = $data['auth']['domain'];
$task_id = intval($data['properties']['task_id']);
$entity_type = $data['properties']['entity_type'];
$entity_id = intval($data['properties']['entity_id']);
$field_code = $data['properties']['field_code'];
$smart_process_id = isset($data['properties']['smart_process_id']) ? intval($data['properties']['smart_process_id']) : null;
$eventToken = isset($data['event_token']) ? $data['event_token'] : null;

function convertFieldCode($fieldCode)
{
    if (preg_match('/^UF_CRM_(_?\d+)(?:_(\d+))?$/', $fieldCode, $matches)) {
        $result = 'ufCrm_' . $matches[1];
        if (!empty($matches[2])) {
            $result .= '_' . $matches[2];
        }
        return $result;
    }
    return $fieldCode;
}

if($entity_type == 'smart_process') {
    $field_code = convertFieldCode($field_code);
}

// Функция вызова Bitrix24 API
function callB24Api($method, $params, $access_token, $domain)
{
    $url = "https://{$domain}/rest/{$method}?auth={$access_token}";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        logToFile('CURL Error: ' . curl_error($ch));
        return false;
    }
    curl_close($ch);
    return json_decode($response, true);
}

// Функция получения содержимого файла через Bitrix24 API
function getFileContent($fileId, $access_token, $domain)
{
    // Получаем информацию о файле включая download URL
    $fileInfo = callB24Api('disk.file.get', ['id' => $fileId], $access_token, $domain);

    if (!$fileInfo || !isset($fileInfo['result']['DOWNLOAD_URL'])) {
        logToFile(['file_info_error' => 'Не удалось получить информацию о файле', 'file_id' => $fileId]);
        logToFile($fileInfo);
        return false;
    }

    $downloadUrl = $fileInfo['result']['DOWNLOAD_URL'];
    $fileName = $fileInfo['result']['NAME'];

    // Скачиваем содержимое файла
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $downloadUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

    $content = curl_exec($ch);

    if (curl_errno($ch)) {
        logToFile('CURL Download Error: ' . curl_error($ch));
        curl_close($ch);
        return false;
    }

    curl_close($ch);

    return ['content' => $content, 'name' => $fileName];
}

// Функция определения типа поля (множественное или нет)
function isFieldMultiple($entity_type, $field_code, $smart_process_id, $access_token, $domain)
{
    $method = "crm.item.fields";
    $params = [];

    // Определяем entityTypeId в зависимости от типа сущности
    switch ($entity_type) {
        case 'lead':
            $params['entityTypeId'] = 1;
            break;
        case 'deal':
            $params['entityTypeId'] = 2;
            break;
        case 'contact':
            $params['entityTypeId'] = 3;
            break;
        case 'company':
            $params['entityTypeId'] = 4;
            break;
        case 'smart_process':
            if (!$smart_process_id) {
                logToFile('Ошибка: Для смарт-процесса необходимо указать smart_process_id');
                return false;
            }
            $params['entityTypeId'] = $smart_process_id;
            break;
        default:
            logToFile(['unsupported_entity_type_for_fields' => $entity_type]);
            return false;
    }

    $fieldsResult = callB24Api($method, $params, $access_token, $domain);

    if (!$fieldsResult || !isset($fieldsResult['result'])) {
        logToFile(['fields_request_error' => $fieldsResult]);
        return false;
    }

    if (!isset($fieldsResult['result']['fields'])) {
        logToFile(['fields_structure_error' => 'Нет ключа fields в result', 'result_structure' => array_keys($fieldsResult['result'])]);
        return false;
    }

    $fields = $fieldsResult['result']['fields'];

    if (!is_array($fields)) {
        logToFile(['fields_not_array' => 'Поля не являются массивом', 'fields_type' => gettype($fields)]);
        return false;
    }

    if (!isset($fields[$field_code])) {
        logToFile(['field_not_found' => $field_code, 'available_fields' => array_keys($fields)]);
        return false;
    }

    $fieldInfo = $fields[$field_code];

    // Детальная проверка признаков множественности
    $isMultiple = false;

    if (isset($fieldInfo['isMultiple'])) {
        if ($fieldInfo['isMultiple'] === true || $fieldInfo['isMultiple'] === 'Y' || $fieldInfo['isMultiple'] == 1) {
            $isMultiple = true;
        }
    }

    if (isset($fieldInfo['multiple'])) {
        if ($fieldInfo['multiple'] === true || $fieldInfo['multiple'] === 'Y' || $fieldInfo['multiple'] == 1) {
            $isMultiple = true;
        }
    }

    if (isset($fieldInfo['type']) && $fieldInfo['type'] === 'file') {
        if (isset($fieldInfo['isMultiple']) && $fieldInfo['isMultiple']) {
            $isMultiple = true;
        }
    }

    logToFile([
        'field_detailed_check' => [
            'field_code' => $field_code,
            'field_type' => isset($fieldInfo['type']) ? $fieldInfo['type'] : 'unknown',
            'isMultiple_value' => isset($fieldInfo['isMultiple']) ? $fieldInfo['isMultiple'] : 'not_set',
            'multiple_value' => isset($fieldInfo['multiple']) ? $fieldInfo['multiple'] : 'not_set',
            'is_multiple_detected' => $isMultiple,
            'field_info_keys' => array_keys($fieldInfo)
        ]
    ]);

    return $isMultiple;
}

// Функция обновления сущности
function updateEntity($entity_type, $entity_id, $field_code, $fileIds, $smart_process_id, $access_token, $domain)
{
    // Определяем, является ли поле множественным
    $isMultiple = isFieldMultiple($entity_type, $field_code, $smart_process_id, $access_token, $domain);

    // Преобразуем массив ID файлов в правильный формат для Bitrix24
    $fileValues = [];
    if ($entity_type != 'smart_process') {
        foreach ($fileIds as $fileId) {
            $fileData = getFileContent($fileId, $access_token, $domain);
            if ($fileData) {
                $fileValues[] = [
                    'fileData' => [
                        $fileData['name'],
                        base64_encode($fileData['content'])
                    ]
                ];
            } else {
                logToFile(['file_preparation_failed' => $fileId]);
            }
        }
    } else {
        foreach ($fileIds as $fileId) {
            $fileData = getFileContent($fileId, $access_token, $domain);
            if ($fileData) {
                $fileValues[] = [
                    $fileData['name'],
                    base64_encode($fileData['content'])
                ];
            } else {
                logToFile(['file_preparation_failed' => $fileId]);
            }
        }
    }

    if (empty($fileValues)) {
        logToFile('Нет файлов для записи после обработки');
        return false;
    }

    // Определяем формат передачи файлов на основе типа поля
    if ($isMultiple) {
        $fieldValue = $fileValues;
    } else {
        $fieldValue = $fileValues[0];
        if (count($fileValues) > 1) {
            logToFile(['warning' => 'Множественные файлы для одиночного поля, берем только первый', 'files_count' => count($fileValues)]);
        }
    }

    $params = [
        'id' => $entity_id,
        'fields' => [
            $field_code => $fieldValue
        ]
    ];

    logToFile('ТИП СУЩНОСТИ ДЛЯ ОБНОВЛЕНИЯ: ' . $entity_type);
    
    $method = 'crm.item.update';
    switch ($entity_type) {
        case 'lead':
            $method = 'crm.lead.update';
            break;
        case 'contact':
            $method = 'crm.contact.update';
            break;
        case 'company':
            $method = 'crm.company.update';
            break;
        case 'deal':
            $method = 'crm.deal.update';
            break;
        case 'smart_process':
            if (!$smart_process_id) {
                logToFile('Ошибка: Для смарт-процесса необходимо указать smart_process_id');
                return false;
            }
            $params['entityTypeId'] = $smart_process_id;
            break;
        default:
            logToFile(['unsupported_entity_type' => $entity_type]);
            return false;
    }

    $result = callB24Api($method, $params, $access_token, $domain);

    if ($result && isset($result['result'])) {
        return true;
    } else {
        logToFile(['entity_update_error' => $result]);
        return false;
    }
}

// Функция отправки результата в бизнес-процесс
function sendBizprocResult($eventToken, $returnValues, $access_token, $domain)
{
    if (!$eventToken) {
        logToFile('Предупреждение: event_token не найден, результат не отправлен в БП');
        return true;
    }

    return callB24Api(
        'bizproc.event.send',
        [
            'event_token' => $eventToken,
            'return_values' => $returnValues
        ],
        $access_token,
        $domain
    );
}

// Функция для получения самого последнего результата по дате создания
function getLatestResult($results)
{
    if (empty($results)) {
        return null;
    }

    $latestResult = null;
    $latestTimestamp = 0;

    foreach ($results as $result) {
        if (isset($result['createdAt'])) {
            $timestamp = strtotime($result['createdAt']);
            if ($timestamp > $latestTimestamp) {
                $latestTimestamp = $timestamp;
                $latestResult = $result;
            }
        }
    }

    // Если не нашли по createdAt, берем последний элемент массива
    return $latestResult ?: end($results);
}

try {
    // 1. Получаем данные задачи
    $task = callB24Api("tasks.task.get", ['taskId' => $task_id], $access_token, $domain);
    if (!$task || !isset($task['result']['task'])) {
        logToFile("Ошибка: Задача #{$task_id} не найдена");

        $returnValues = [
            'success' => false,
            'message' => "Задача #{$task_id} не найдена"
        ];

        sendBizprocResult($eventToken, $returnValues, $access_token, $domain);

        http_response_code(404);
        echo json_encode(['error' => "Задача #{$task_id} не найдена"]);
        exit;
    }

    $taskData = $task['result']['task'];
    logToFile("Получена задача #{$task_id}: " . $taskData['title']);

    // 2. Получаем все результаты задачи
    $taskResults = callB24Api("tasks.task.result.list", ['taskId' => $task_id], $access_token, $domain);
    
    if (!$taskResults || !isset($taskResults['result'])) {
        logToFile("Предупреждение: Не удалось получить результаты задачи #{$task_id}");
        $taskResults = ['result' => []];
    }

    $results = $taskResults['result'];
    logToFile("Найдено результатов задачи: " . count($results));

    // 3. Обрабатываем результаты - находим самый последний
    $fileIds = [];
    $textResult = '';

    if (!empty($results)) {
        // Находим самый последний результат по дате создания
        $result = getLatestResult($results);
        
        logToFile("Обрабатываем последний результат задачи (ID: " . $result['ID'] . ", дата: " . ($result['createdAt'] ?? 'не указана') . ")");

        // Получаем файлы
        if (!empty($result['files']) && is_array($result['files'])) {
            $fileIds = $result['files'];
            logToFile("Найдены файлы в результате: " . implode(', ', $fileIds));

            // Получаем реальные FILE_ID из комментария
            if (!empty($result['commentId'])) {
                $commentInfo = callB24Api("task.commentitem.get", [
                    'TASKID' => $task_id,
                    'ITEMID' => $result['commentId']
                ], $access_token, $domain);

                if ($commentInfo && isset($commentInfo['result']['ATTACHED_OBJECTS'])) {
                    $realFileIds = [];
                    foreach ($commentInfo['result']['ATTACHED_OBJECTS'] as $attachedFile) {
                        if (isset($attachedFile['FILE_ID'])) {
                            $realFileIds[] = $attachedFile['FILE_ID'];
                        }
                    }

                    if (!empty($realFileIds)) {
                        $fileIds = $realFileIds;
                        logToFile("Заменены на реальные FILE_ID: " . implode(', ', $fileIds));
                    } else {
                        logToFile(['no_file_ids_in_attached_objects' => 'FILE_ID не найден в ATTACHED_OBJECTS']);
                    }
                } else {
                    logToFile(['comment_debug' => [
                        'comment_info_exists' => isset($commentInfo),
                        'has_result' => isset($commentInfo['result']),
                        'has_attached_objects' => isset($commentInfo['result']['ATTACHED_OBJECTS']),
                        'comment_response' => $commentInfo
                    ]]);
                }
            } else {
                logToFile(['no_comment_id' => 'commentId отсутствует в результате задачи']);
            }
        }

        // Получаем текстовый результат
        if (!empty($result['text'])) {
            $textResult = $result['text'];
            logToFile("Текстовый результат: " . substr($textResult, 0, 100) . "...");
        }
    } else {
        logToFile("Нет результатов у задачи #{$task_id}");
    }

    // 4. Записываем файлы в сущность, если есть файлы
    $entityUpdateSuccess = false;
    if (!empty($fileIds)) {
        logToFile("Начинаем запись " . count($fileIds) . " файлов в сущность {$entity_type}#{$entity_id}");
        
        $entityUpdateSuccess = updateEntity(
            $entity_type,
            $entity_id,
            $field_code,
            $fileIds,
            $smart_process_id,
            $access_token,
            $domain
        );
        
        logToFile("Результат обновления сущности: " . ($entityUpdateSuccess ? 'УСПЕХ' : 'ОШИБКА'));
    } else {
        logToFile('Нет файлов для записи в сущность');
        $entityUpdateSuccess = true; // Считаем успешным, если нет файлов
    }

    // 5. Формируем возвращаемые значения
    $returnValues = [
        'success' => $entityUpdateSuccess,
        'files_count' => count($fileIds),
        'files_ids' => implode(',', $fileIds),
        'text_result' => $textResult,
        'message' => $entityUpdateSuccess ?
            'Файлы успешно записаны в сущность' :
            'Ошибка при записи файлов в сущность'
    ];

    // 6. Отправляем результат в бизнес-процесс
    $bizprocResult = sendBizprocResult($eventToken, $returnValues, $access_token, $domain);

    // 7. Формируем ответ
    $response = [
        'success' => $entityUpdateSuccess,
        'message' => $returnValues['message'],
        'files_count' => count($fileIds),
        'files_ids' => $fileIds,
        'text_result' => $textResult,
        'entity_updated' => $entityUpdateSuccess
    ];

    logToFile("Финальный ответ: " . json_encode($response));
    echo json_encode($response);
} catch (Exception $e) {
    // Обработка исключений
    $errorMessage = 'Внутренняя ошибка сервера: ' . $e->getMessage();

    $returnValues = [
        'success' => false,
        'message' => $errorMessage,
        'files_count' => 0,
        'files_ids' => '',
        'text_result' => ''
    ];

    sendBizprocResult($eventToken, $returnValues, $access_token, $domain);

    logToFile(['exception' => $errorMessage, 'trace' => $e->getTraceAsString()]);
    http_response_code(500);
    echo json_encode(['error' => $errorMessage]);
}