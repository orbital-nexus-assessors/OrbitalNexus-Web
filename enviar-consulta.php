<?php

declare(strict_types=1);
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

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

/**
 * Redirige a la página visual correspondiente según
 * el idioma y el resultado del procesamiento.
 *
 * El código 303 hace que la siguiente petición sea GET,
 * evitando repetir el envío POST al actualizar la página.
 */
function redirectToResult(string $language, string $result): never
{
    $routes = [
        'ca' => [
            'success' => '/consulta-enviada.html',
            'error' => '/error-consulta.html',
        ],
        'es' => [
            'success' => '/es/consulta-enviada.html',
            'error' => '/es/error-consulta.html',
        ],
    ];

    $location = $routes[$language][$result]
        ?? $routes['ca']['error'];

    header('Location: ' . $location, true, 303);
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
        'send_error' => 'No hem pogut enviar la consulta. Torna-ho a provar més tard o contacta amb nosaltres per correu o telèfon.',
        'success' => 'La consulta s’ha enviat correctament. Ens posarem en contacte amb tu tan aviat com sigui possible.',
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
        'send_error' => 'No hemos podido enviar la consulta. Vuelve a intentarlo más tarde o contacta con nosotros por correo o teléfono.',
        'success' => 'La consulta se ha enviado correctamente. Nos pondremos en contacto contigo lo antes posible.',
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
    redirectToResult($language, 'success');
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
    redirectToResult($language, 'error');
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
    redirectToResult($language, 'error');
}

$config = require $configPath;

if (!is_array($config)) {
    redirectToResult($language, 'error');
}

/*
 * Comprobamos que la configuración contiene todos los valores necesarios.
 */
$requiredConfigKeys = [
    'host',
    'port',
    'encryption',
    'username',
    'password',
    'from_email',
    'from_name',
    'to_email',
    'to_name',
];

foreach ($requiredConfigKeys as $key) {
    if (
        !array_key_exists($key, $config)
        || $config[$key] === ''
        || $config[$key] === null
    ) {
        redirectToResult($language, 'error');
    }
}

/*
 * Composer carga automáticamente PHPMailer desde vendor/.
 */
$autoloadPath = __DIR__ . '/vendor/autoload.php';

if (!is_file($autoloadPath)) {
    redirectToResult($language, 'error');
}

require $autoloadPath;

/*
 * Traducimos nuestra palabra de configuración a la constante
 * utilizada internamente por PHPMailer.
 */
$encryption = match ((string) $config['encryption']) {
    'smtps' => PHPMailer::ENCRYPTION_SMTPS,
    'starttls' => PHPMailer::ENCRYPTION_STARTTLS,
    default => null,
};

if ($encryption === null) {
    redirectToResult($language, 'error');
}

/*
 * Preparamos un mensaje interno legible para la gestoría.
 */
$receivedAt = new DateTimeImmutable(
    'now',
    new DateTimeZone('Europe/Madrid')
);

$phoneForEmail = $phone !== ''
    ? $phone
    : 'No indicado';

$languageForEmail = $language === 'ca'
    ? 'Catalán'
    : 'Castellano';

$emailBody = implode("\n", [
    'Nueva consulta recibida desde la web de Orbital Nexus',
    '',
    'Nombre: ' . $name,
    'Correo electrónico: ' . $email,
    'Teléfono: ' . $phoneForEmail,
    'Idioma del formulario: ' . $languageForEmail,
    'Fecha de recepción: ' . $receivedAt->format('d/m/Y H:i:s'),
    '',
    'Asunto indicado por el usuario:',
    $subject,
    '',
    'Consulta:',
    $message,
]);

$mail = new PHPMailer(true);

try {
    /*
     * Conexión segura con el servidor SMTP de Hostinger.
     */
    $mail->isSMTP();
    $mail->Host = (string) $config['host'];
    $mail->SMTPAuth = true;
    $mail->Username = (string) $config['username'];
    $mail->Password = (string) $config['password'];
    $mail->SMTPSecure = $encryption;
    $mail->Port = (int) $config['port'];

    /*
     * Configuración del mensaje.
     */
    $mail->CharSet = 'UTF-8';
    $mail->Hostname = 'orbitalnexusassessors.com';

    $mail->setFrom(
        (string) $config['from_email'],
        (string) $config['from_name']
    );

    $mail->addAddress(
        (string) $config['to_email'],
        (string) $config['to_name']
    );

    /*
     * Al pulsar Responder, la respuesta se dirigirá al visitante,
     * aunque el remitente técnico sea laboral@orbitalnexus.es.
     */
    $mail->addReplyTo($email, $name);

    $mail->Subject = '[Formulario web] ' . $subject;
    $mail->Body = $emailBody;
    $mail->isHTML(false);

    $mail->send();

    redirectToResult($language, 'success');

} catch (Exception $exception) {
    /*
     * Guardamos información técnica en el registro del servidor,
     * pero nunca se la mostramos al visitante.
     */
    error_log(
        'Error SMTP del formulario de contacto: '
        . $mail->ErrorInfo
    );

    redirectToResult($language, 'error');
}