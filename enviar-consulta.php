<?php

declare(strict_types=1);

/**
 * Devuelve un campo POST únicamente cuando contiene texto.
 * Esto evita errores si alguien manipula la petición y envía un array.
 */
function getPostString(string $field): string
{
    $value = $_POST[$field] ?? '';

    return is_string($value) ? trim($value) : '';
}

/**
 * Calcula la longitud del texto.
 * Usa mb_strlen cuando está disponible para contar correctamente
 * caracteres como á, é, ç o ñ.
 */
function textLength(string $value): int
{
    return function_exists('mb_strlen')
        ? mb_strlen($value, 'UTF-8')
        : strlen($value);
}

/**
 * Finaliza la petición mostrando una respuesta de texto sencilla.
 */
function respond(int $statusCode, string $message): never
{
    http_response_code($statusCode);
    header('Content-Type: text/plain; charset=UTF-8');
    echo $message;
    exit;
}

/*
 * El formulario solo puede procesarse mediante POST.
 * Abrir enviar-consulta.php directamente en el navegador utiliza GET.
 */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    respond(405, 'Método no permitido.');
}

/*
 * Solo aceptamos los dos idiomas existentes.
 * Si alguien manipula el valor, utilizamos catalán como alternativa.
 */
$language = getPostString('language');

if (!in_array($language, ['ca', 'es'], true)) {
    $language = 'ca';
}

$messages = [
    'ca' => [
        'invalid_intro' => 'No hem pogut validar la consulta:',
        'name' => 'Revisa el nom i els cognoms.',
        'email' => 'Introdueix una adreça electrònica vàlida.',
        'phone' => 'Revisa el número de telèfon.',
        'subject' => 'L’assumpte ha de tenir entre 3 i 120 caràcters.',
        'message' => 'La consulta ha de tenir entre 20 i 3.000 caràcters.',
        'privacy' => 'Has de confirmar que has llegit la informació sobre protecció de dades.',
        'config' => 'No s’ha pogut carregar la configuració del servidor.',
        'success' => 'Validació correcta. En aquesta fase de prova encara no s’ha enviat cap correu.',
    ],
    'es' => [
        'invalid_intro' => 'No hemos podido validar la consulta:',
        'name' => 'Revisa el nombre y los apellidos.',
        'email' => 'Introduce una dirección de correo electrónico válida.',
        'phone' => 'Revisa el número de teléfono.',
        'subject' => 'El asunto debe tener entre 3 y 120 caracteres.',
        'message' => 'La consulta debe tener entre 20 y 3.000 caracteres.',
        'privacy' => 'Debes confirmar que has leído la información sobre protección de datos.',
        'config' => 'No se ha podido cargar la configuración del servidor.',
        'success' => 'Validación correcta. En esta fase de prueba todavía no se ha enviado ningún correo.',
    ],
];

$text = $messages[$language];

/*
 * Honeypot: una persona no debería rellenar este campo.
 * Respondemos como si todo fuera correcto para no explicar al bot
 * que hemos detectado su comportamiento.
 */
$website = getPostString('website');

if ($website !== '') {
    respond(200, $text['success']);
}

/*
 * Recuperación de los datos visibles del formulario.
 */
$name = getPostString('name');
$email = getPostString('email');
$phone = getPostString('phone');
$subject = getPostString('subject');
$message = getPostString('message');
$privacyAcknowledged = getPostString('privacy_acknowledged');

/*
 * Validación del servidor.
 */
$errors = [];

if (
    textLength($name) < 2
    || textLength($name) > 100
    || preg_match('/[\r\n]/', $name)
) {
    $errors[] = $text['name'];
}

if (
    textLength($email) > 254
    || filter_var($email, FILTER_VALIDATE_EMAIL) === false
    || preg_match('/[\r\n]/', $email)
) {
    $errors[] = $text['email'];
}

if (
    $phone !== ''
    && (
        textLength($phone) > 30
        || preg_match('/^[0-9+().\s-]+$/', $phone) !== 1
    )
) {
    $errors[] = $text['phone'];
}

if (
    textLength($subject) < 3
    || textLength($subject) > 120
    || preg_match('/[\r\n]/', $subject)
) {
    $errors[] = $text['subject'];
}

if (
    textLength($message) < 20
    || textLength($message) > 3000
) {
    $errors[] = $text['message'];
}

if ($privacyAcknowledged !== '1') {
    $errors[] = $text['privacy'];
}

if ($errors !== []) {
    $errorList = '- ' . implode("\n- ", $errors);

    respond(
        422,
        $text['invalid_intro'] . "\n\n" . $errorList
    );
}

/*
 * En producción, la configuración estará fuera de public_html.
 * En local, utilizamos smtp-config.php dentro del proyecto.
 */
$configPaths = [
    dirname(__DIR__) . '/private/smtp-config.php',
    __DIR__ . '/smtp-config.php',
];

$configPath = null;

foreach ($configPaths as $candidatePath) {
    if (is_file($candidatePath)) {
        $configPath = $candidatePath;
        break;
    }
}

if ($configPath === null) {
    respond(500, $text['config']);
}

$config = require $configPath;

if (!is_array($config)) {
    respond(500, $text['config']);
}

/*
 * Punto alcanzado cuando la petición y la configuración son válidas.
 * En la siguiente fase añadiremos aquí el envío con PHPMailer.
 */
respond(200, $text['success']);