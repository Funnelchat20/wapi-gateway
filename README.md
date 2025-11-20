# WAPI Gateway (SDK)

SDK puro para integrar proveedores de WhatsApp soportados por Funnelchat: ZApi, UAZAPI y Meta (WhatsApp Cloud), sin rutas ni middlewares. La app host consume métodos programáticos mediante un Facade y decide si expone endpoints propios.

## Requisitos
- PHP ^8.2
- Laravel ^11.0

## Instalación

### Packagist (recomendado)
```bash
composer require funnelchat20/wapi-gateway
```

### Alternativa de desarrollo (VCS/Path)
Agrega en `composer.json` del proyecto host:
```json
{
  "repositories": [
    { "type": "vcs", "url": "https://github.com/Funnelchat20/wapi-gateway" }
  ]
}
```
e instala:
```bash
composer require funnelchat20/wapi-gateway:*@dev
```

## Configuración
Publica los archivos de configuración y ajusta las variables de entorno por proveedor:
```bash
php artisan vendor:publish --tag=wapi-config
```

### Variables de entorno
- ZApi:
  - `ZAPI_TOKEN`
  - `ZAPI_CLIENT_TOKEN`
  - `ZAPI_TIMEOUT` (default: `29` segundos)
  - `ZAPI_MAX_ATTEMPTS` (default: `2` reintentos)
  - `ZAPI_RETRY_DELAY` (default: `500` ms entre reintentos)
- UAZAPI:
  - `UAZAPI_BASE_URL` (ej.: `https://funnelchat.uazapi.com`)
  - `UAZAPI_ADMIN_TOKEN`
  - `UAZAPI_TIMEOUT` (default: `120`)
- Meta (WhatsApp Cloud):
  - `META_APP_ID` (para uploads de media en templates)
  - `AWS_BUCKET_URL` (si utilizas subida/descarga de archivos en flujos avanzados)

## Uso
Importa el Facade y el enum de proveedor:
```php
use Funnelchat\WapiGateway\Facades\WapiGateway;
use Funnelchat\WapiGateway\Enums\ProviderEnum;
```

### Enviar mensaje de texto
ZApi:
```php
$response = WapiGateway::messages(ProviderEnum::ZApi)
    ->sendText($uid, $token, $phone, 'Hola mundo', [
        'delayMessage' => 0,
        'delayTyping' => 0,
        'retry' => true,  // Habilita reintentos automáticos en errores de conexión
    ]);

if (isset($response['error'])) {
    // manejar error
}
```

UAZAPI:
```php
$response = WapiGateway::messages(ProviderEnum::Uazapi)
    ->sendText($uid, $token, $phone, 'Hola UAZAPI');
```

Meta (WhatsApp Cloud):
```php
$response = WapiGateway::messages(ProviderEnum::WhatsAppCloud)
    ->sendText($uid, $token, $phone, 'Hola Meta');
```

### Crear instancia
ZApi:
```php
$data = WapiGateway::instances(ProviderEnum::ZApi)->create($userId, $deviceId);
// $data = ['uid' => '...', 'token' => '...'] o ['error' => '...']
```

UAZAPI:
```php
$data = WapiGateway::instances(ProviderEnum::Uazapi)->create($userId, $deviceId);
```

### Eliminación concurrente de mensajes (v1.3.0+)
Elimina múltiples mensajes en paralelo para mejor performance:
```php
$deleteRequests = [
    ['messageId' => 'msg1', 'phone' => '123456789', 'owner' => true],
    ['messageId' => 'msg2', 'phone' => '123456789', 'owner' => true],
    // ... más mensajes
];

$results = WapiGateway::messages(ProviderEnum::ZApi)
    ->deleteMessagesConcurrently($uid, $token, $deleteRequests);

// $results es un array indexado por messageId con el resultado de cada eliminación
foreach ($results as $messageId => $result) {
    if (isset($result['error'])) {
        echo "Error eliminando {$messageId}: {$result['error']}";
    }
}
```

### Envío de videos con procesamiento asíncrono (v1.3.0+)
Los videos se procesan automáticamente de forma asíncrona para evitar timeouts:
```php
// Automático para archivos .mp4, .mov, .gif
$response = WapiGateway::messages(ProviderEnum::ZApi)
    ->sendFile($uid, $token, $phone, 'https://example.com/video.mp4', [
        'caption' => 'Mi video',
        'retry' => true,
        // 'async' => false  // Desactivar async si es necesario
    ]);
```

## Manejo de errores
Los métodos retornan arrays con la respuesta del proveedor o `['error' => '...']` cuando falla. Normaliza mensajes comunes (p. ej. ZApi: `instance_not_found`, `pending_subscription`, etc.).

### Retry automático (v1.3.0+)
El SDK incluye lógica de retry inteligente que:
- Solo reintenta en errores de conexión (`ConnectionException`)
- NO reintenta en timeouts (evita mensajes duplicados)
- Es configurable vía variables de entorno o por request
- Incluye logging detallado de cada intento

Para habilitar retry en un método:
```php
$response = WapiGateway::messages(ProviderEnum::ZApi)
    ->sendText($uid, $token, $phone, 'Mensaje', ['retry' => true]);
```

## Integración en controladores/Jobs
Este SDK no agrega rutas: úsalo dentro de tus controladores/servicios/Jobs. Ejemplo en un controlador:
```php
public function send(Request $request)
{
    $response = WapiGateway::messages(ProviderEnum::ZApi)
        ->sendText($request->uid, $request->header('token'), $request->phone, $request->message);

    return isset($response['error'])
        ? response()->json(['error' => $response['error']], 409)
        : response()->json($response);
}
```

## Proveedores soportados
- `ProviderEnum::ZApi`
- `ProviderEnum::Uazapi`
- `ProviderEnum::WhatsAppCloud`

## Changelog

### v1.3.0 - 2025-11-20
**Added:**
- ✨ Método `deleteMessagesConcurrently()` para eliminación paralela de mensajes con HTTP Pool
- ✨ Retry configurable en métodos de envío (`sendText`, `sendFile`, `sendButtons`, `sendPoll`)
- ✨ Timeouts configurables vía variables de entorno (`ZAPI_TIMEOUT`, `ZAPI_MAX_ATTEMPTS`, `ZAPI_RETRY_DELAY`)
- ✨ Procesamiento asíncrono automático para videos (`.mp4`, `.mov`, `.gif`)
- ✨ Logging mejorado con métricas de rendimiento (`request_time_ms`, `status_code`, etc.)

**Changed:**
- 🔧 Timeout por defecto reducido de 120s a 29s (configurable)
- 🔧 Retry inteligente: no reintenta en timeouts, solo en `ConnectionException`

**Performance:**
- ⚡ Eliminación de 100 mensajes: ~2s con HTTP Pool vs ~30s+ secuencial
- ⚡ Reducción de timeouts en envíos de videos gracias a procesamiento async

## Roadmap
- Completar módulos del SDK (location, templates) reflejando la funcionalidad existente.
- Documentar DTOs y respuestas normalizadas por operación.
- Agregar soporte para webhooks (opcional)

## Licencia
Privado (Funnelchat). Contacto: soporte@funnelchat.io

