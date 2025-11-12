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

## Manejo de errores
Los métodos retornan arrays con la respuesta del proveedor o `['error' => '...']` cuando falla. Normaliza mensajes comunes (p. ej. ZApi: `instance_not_found`, `pending_subscription`, etc.).

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

## Roadmap
- Completar módulos del SDK (files, location, buttons, option-list, poll, groups, templates) reflejando la funcionalidad existente.
- Documentar DTOs y respuestas normalizadas por operación.

## Licencia
Privado (Funnelchat). Contacto: soporte@funnelchat.io

