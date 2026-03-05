# WAPI Gateway (SDK)

[![Latest Version on Packagist](https://img.shields.io/packagist/v/funnelchat20/wapi-gateway.svg?style=flat-square)](https://packagist.org/packages/funnelchat20/wapi-gateway)
[![Total Downloads](https://img.shields.io/packagist/dt/funnelchat20/wapi-gateway.svg?style=flat-square)](https://packagist.org/packages/funnelchat20/wapi-gateway)

SDK puro para integrar proveedores de WhatsApp soportados por Funnelchat: **Z-API**, **UAZAPI**, **Funapi** (Whatsmeow Bridge) y **Meta** (WhatsApp Cloud), sin rutas ni middlewares. La app host consume métodos programáticos mediante un Facade y decide si expone endpoints propios.

> **⚠️ Estado:** Esta librería está en desarrollo activo (v0.x.x). La API puede cambiar entre versiones menores. Se recomienda fijar versiones específicas en composer.json hasta v1.0.0.

## Requisitos
- PHP ^8.2
- Laravel ^11.0 | ^12.0

## Instalación

```bash
composer require funnelchat20/wapi-gateway
```

### Publicar configuración

```bash
php artisan vendor:publish --tag=wapi-config
```

## Configuración

### Variables de entorno por proveedor

#### Global (Todos los proveedores)
```env
# URL base para webhooks (requerida para recibir notificaciones)
WEBHOOK_BASE_URL=https://tu-app.com
```

#### Z-API
```env
ZAPI_TOKEN=tu_token_aqui
ZAPI_CLIENT_TOKEN=tu_client_token
ZAPI_TIMEOUT=29
ZAPI_MAX_ATTEMPTS=2
ZAPI_RETRY_DELAY=500
```

#### UAZAPI
```env
UAZAPI_BASE_URL=https://funnelchat.uazapi.com
UAZAPI_ADMIN_TOKEN=tu_admin_token
UAZAPI_TIMEOUT=120

# Opcional: deshabilitar configuración automática de webhooks
UAZAPI_AUTO_CONFIGURE_WEBHOOKS=true
```

#### Funapi (Whatsmeow Bridge)
```env
FUNAPI_BASE_URL=https://api.funapi.example.com
FUNAPI_TOKEN=tu_token
FUNAPI_CLIENT_TOKEN=tu_client_token
FUNAPI_TIMEOUT=29
FUNAPI_MAX_ATTEMPTS=2
FUNAPI_RETRY_DELAY=500
```

#### Meta (WhatsApp Cloud)
```env
META_APP_ID=tu_app_id
AWS_BUCKET_URL=https://tu-bucket.s3.amazonaws.com
```

## Uso básico

```php
use Funnelchat\WapiGateway\Facades\WapiGateway;
use Funnelchat\WapiGateway\Enums\ProviderEnum;

// Enviar mensaje de texto
$response = WapiGateway::messages(ProviderEnum::ZApi)
    ->sendText($uid, $token, $phone, 'Hola mundo', [
        'delayMessage' => 0,
        'delayTyping' => 0,
        'retry' => true,  // Habilita reintentos automáticos
    ]);

if (isset($response['error'])) {
    // Manejar error
}

// Crear instancia
$data = WapiGateway::instances(ProviderEnum::ZApi)->create($userId, $deviceId);
// $data = ['uid' => '...', 'token' => '...'] o ['error' => '...']

// Obtener estado
$status = WapiGateway::instances(ProviderEnum::ZApi)->status($uid, $token);
// Incluye 'accountStatus' y 'qrCode' automáticamente
```

## Compatibilidad de Proveedores

### 📨 Mensajería

| Método | Z-API | UAZAPI | Funapi | Meta |
|--------|-------|--------|--------|------|
| `sendText()` | ✅ | ✅ | ✅ | ✅ |
| `sendFile()` | ✅ | ✅ | ✅ | ✅ |
| `sendLocation()` | ✅ | ✅ | ✅ | ✅ |
| `sendButtons()` | ✅ | ✅ | ⚠️ | ✅ |
| `sendButtonLink()` | ✅ | ✅ | ⚠️ | ✅ |
| `sendOptionList()` | ✅ | ✅ | ⚠️ | ✅ |
| `sendPoll()` | ✅ | ✅ | ⚠️ | ✅ |
| `sendLink()` | ✅ | ✅ | ⚠️ | ✅ |
| `sendEvent()` | ✅ | ✅ | ⚠️ | ✅ |
| `sendTemplate()` | ✅ | ✅ | ⚠️ | ✅ |
| `pinMessage()` | ✅ | ✅ | ⚠️ | ❌ |

### 🔧 Instancias

| Método | Z-API | UAZAPI | Funapi | Meta |
|--------|-------|--------|--------|------|
| `create()` | ✅ | ✅ | ✅ | ✅ |
| `status()` | ✅ | ✅ | ✅ | ✅ |
| `qrCode()` | ✅ | ✅ | ✅ | ✅ |
| `logout()` | ✅ | ✅ | ✅ | ✅ |
| `reboot()` | ✅ | ✅ | ✅ | ✅ |
| `me()` | ✅ | ✅ | ✅ | ✅ |
| `checkPhone()` | ✅ | ✅ | ❌ | ✅ |
| `subscribe()` | ✅ | ✅ | ✅ | ✅ |
| `unsubscribe()` | ✅ | ✅ | ✅ | ✅ |

### 👥 Grupos

| Método | Z-API | UAZAPI | Funapi | Meta |
|--------|-------|--------|--------|------|
| `groups()` | ✅ | ✅ | ✅ | ❌ |
| `group()` | ✅ | ✅ | ✅ | ❌ |
| `createGroup()` | ✅ | ✅ | ✅ | ❌ |
| `updateGroupName()` | ✅ | ✅ | ✅ | ❌ |
| `updateGroupDescription()` | ✅ | ✅ | ✅ | ❌ |
| `updateGroupSettings()` | ✅ | ✅ | ✅ | ❌ |
| `updateGroupPhoto()` | ✅ | ✅ | ✅ | ❌ |
| `addParticipants()` | ✅ | ✅ | ✅ | ❌ |
| `addAdmins()` | ✅ | ✅ | ✅ | ❌ |
| `removeParticipants()` | ✅ | ✅ | ✅ | ❌ |
| `removeAdmins()` | ✅ | ✅ | ✅ | ❌ |
| `leaveGroup()` | ✅ | ✅ | ✅ | ❌ |
| `adGroups()` | ✅ | ✅ | ✅ | ❌ |
| `groupInvitationMetadata()` | ✅ | ✅ | ✅ | ❌ |
| `groupInvitationLink()` | ✅ | ✅ | ✅ | ❌ |
| `lightGroupMetadata()` | ✅ | ✅ | ✅ | ❌ |
| `groupMetadata()` | ✅ | ✅ | ✅ | ❌ |

### 📇 Contactos

| Método | Z-API | UAZAPI | Funapi | Meta |
|--------|-------|--------|--------|------|
| `contact()` | ✅ | ✅ | ✅ | ❌ |
| `contacts()` | ✅ | ✅ | ✅ | ❌ |
| `sendContact()` | ✅ | ✅ | ✅ | ✅ |
| `addContacts()` | ✅ | ✅ | ✅ | ❌ |

### 🏘️ Comunidades

| Método | Z-API | UAZAPI | Funapi | Meta |
|--------|-------|--------|--------|------|
| `community()` | ✅ | ✅ | ⚠️ | ❌ |
| `communities()` | ✅ | ✅ | ⚠️ | ❌ |
| `communitiesMetadata()` | ✅ | ✅ | ⚠️ | ❌ |

### 📢 Newsletters / Canales

| Método | Z-API | UAZAPI | Funapi | Meta |
|--------|-------|--------|--------|------|
| `createNewsletter()` | ✅ | ✅ | ⚠️ | ❌ |
| `updateNewsletterName()` | ✅ | ✅ | ⚠️ | ❌ |
| `updateNewsletterDescription()` | ✅ | ✅ | ⚠️ | ❌ |
| `updateNewsletterPicture()` | ✅ | ✅ | ⚠️ | ❌ |
| `newsletters()` | ✅ | ✅ | ⚠️ | ❌ |
| `newsletterMetadata()` | ✅ | ✅ | ⚠️ | ❌ |

### 📋 Cola de Mensajes

| Método | Z-API | UAZAPI | Funapi | Meta |
|--------|-------|--------|--------|------|
| `showQueue()` | ✅ | ✅ | ✅ | ❌ |
| `queueCount()` | ✅ | ✅ | ✅ | ❌ |
| `deleteQueueMessage()` | ✅ | ✅ | ✅ | ❌ |
| `clearQueue()` | ✅ | ✅ | ✅ | ❌ |

### 📝 Templates (Solo Meta)

| Método | Meta |
|--------|------|
| `listTemplates()` | ✅ |
| `getTemplate()` | ✅ |
| `createTemplate()` | ✅ |
| `updateTemplate()` | ✅ |
| `deleteTemplate()` | ✅ |
| `uploadHeaderHandle()` | ✅ |

**Leyenda:**
- ✅ Completamente funcional
- ⚠️ En desarrollo (retorna error descriptivo)
- ❌ No soportado por el proveedor

## Funcionalidades Avanzadas

### Administración de Templates (Meta/WhatsApp Cloud)

```php
use Funnelchat\WapiGateway\Facades\WapiGateway;
use Funnelchat\WapiGateway\Enums\ProviderEnum;

// Listar templates
$templates = WapiGateway::templates(ProviderEnum::WhatsAppCloud)
    ->listTemplates($wabaId, $token, [
        'limit' => 20,
        'after' => 'cursor123'  // Para paginación
    ]);

// Crear template
$template = WapiGateway::templates(ProviderEnum::WhatsAppCloud)
    ->createTemplate($wabaId, $token, [
        'name' => 'welcome_message',
        'language_code' => 'es',
        'category' => 'MARKETING',
        'header' => 'Bienvenido',
        'body' => 'Hola {{1}}, gracias por contactarnos',
        'footer' => 'Equipo de soporte',
        'buttons' => [
            ['type' => 'URL', 'text' => 'Visitar sitio', 'url' => 'https://example.com']
        ]
    ]);

// Eliminar template
$result = WapiGateway::templates(ProviderEnum::WhatsAppCloud)
    ->deleteTemplate($wabaId, $token, 'welcome_message', $templateUid);
```

### Eliminación Concurrente de Mensajes

```php
$deleteRequests = [
    ['messageId' => 'msg1', 'phone' => '123456789', 'owner' => true],
    ['messageId' => 'msg2', 'phone' => '987654321', 'owner' => true],
    // ... más mensajes
];

$results = WapiGateway::groups(ProviderEnum::ZApi)
    ->deleteMessagesConcurrently($uid, $token, $deleteRequests);

// Performance: ~2s para 100 mensajes vs ~30s+ secuencial
foreach ($results as $messageId => $result) {
    if (isset($result['error'])) {
        echo "Error eliminando {$messageId}: {$result['error']}";
    }
}
```

### Procesamiento Asíncrono de Videos

```php
// Automático para .mp4, .mov, .gif
$response = WapiGateway::messages(ProviderEnum::ZApi)
    ->sendFile($uid, $token, $phone, 'https://example.com/video.mp4', [
        'caption' => 'Mi video',
        'retry' => true,
        // 'async' => false  // Desactivar si es necesario
    ]);
```

### Retry Automático

```php
$response = WapiGateway::messages(ProviderEnum::ZApi)
    ->sendText($uid, $token, $phone, 'Mensaje importante', [
        'retry' => true  // Reintenta automáticamente en errores de conexión
    ]);

// El SDK:
// - Solo reintenta en ConnectionException
// - NO reintenta en timeouts (evita duplicados)
// - Configurable vía ZAPI_MAX_ATTEMPTS y ZAPI_RETRY_DELAY
```

## Configuración Automática de Webhooks

El SDK configura webhooks automáticamente al crear instancias para recibir notificaciones en tiempo real de mensajes, conexión/desconexión, y otros eventos.

### ⚙️ Configuración

**Variable requerida:**
```env
WEBHOOK_BASE_URL=https://tu-app.com
```

Si no defines `WEBHOOK_BASE_URL`, el SDK usará `APP_URL` como fallback. Si ninguna está configurada, no se configurarán webhooks automáticamente.

### 📡 Webhooks por Proveedor

#### Z-API
Configurados en el payload de creación de instancia:
- `/webhooks/zapi/received` - Mensajes recibidos
- `/webhooks/zapi/received-and-delivery` - Mensajes y confirmaciones de entrega
- `/webhooks/zapi/disconnected` - Instancia desconectada
- `/webhooks/zapi/connected` - Instancia conectada
- `/webhooks/zapi/message-status` - Estados de mensajes enviados
- `/webhooks/zapi/block` - Bloqueos/desbloqueos

#### UAZAPI
Configurados vía POST `/webhook` después de crear la instancia:
- `/webhooks/uazapi/messages` - Mensajes recibidos
- `/webhooks/uazapi/messages_update` - Actualizaciones de mensajes
- `/webhooks/uazapi/connection` - Cambios de conexión
- `/webhooks/uazapi/messages` - Eventos de grupos (comparte ruta con messages)

**Opciones:**
```env
# Deshabilitar webhooks automáticos para UAZAPI
UAZAPI_AUTO_CONFIGURE_WEBHOOKS=false
```

#### Funapi
Configurados en el payload de creación de instancia:
- `/webhooks/funapi/received` - Mensajes recibidos
- `/webhooks/funapi/received-and-delivery` - Mensajes y confirmaciones de entrega
- `/webhooks/funapi/disconnected` - Instancia desconectada
- `/webhooks/funapi/connected` - Instancia conectada
- `/webhooks/funapi/message-status` - Estados de mensajes enviados
- `/webhooks/funapi/block` - Bloqueos/desbloqueos

### 💡 Ejemplo de Uso

```php
// 1. Configurar .env
// WEBHOOK_BASE_URL=https://mi-app.com

// 2. Crear instancia (webhooks se configuran automáticamente)
$instance = WapiGateway::instances(ProviderEnum::Uazapi)->create($userId, $deviceId);
// Los webhooks ya están configurados en UAZAPI

// 3. La app host debe tener rutas para recibir webhooks
Route::post('/webhooks/uazapi/messages', [WebhookController::class, 'uazapiMessages']);
Route::post('/webhooks/uazapi/connection', [WebhookController::class, 'uazapiConnection']);
// etc...
```

### 🔍 Logging

El SDK registra automáticamente el éxito/error de la configuración de webhooks:

```php
// Logs en UAZAPI
Log::info('UAZAPI webhook configured successfully', [...]);
Log::warning('UAZAPI webhook configuration failed', [...]);
Log::warning('UAZAPI webhook configuration skipped: WEBHOOK_BASE_URL not configured', [...]);
```

## Sistema de Resources

El SDK transforma las respuestas de cada proveedor para mantener compatibilidad con WAPI original:

```php
// Ejemplo: status() con fetch automático de QR
$status = WapiGateway::instances(ProviderEnum::ZApi)->status($uid, $token);
// Retorna: ['accountStatus' => 'authenticated', 'qrCode' => null]
// o: ['accountStatus' => 'got qr code', 'qrCode' => 'data:image/png;base64,...']

// checkPhone() normalizado
$result = WapiGateway::instances(ProviderEnum::ZApi)->checkPhone($uid, $token, $phone);
// Retorna: ['result' => true] (no 'exists', sino 'result')

// me() con campos adicionales
$me = WapiGateway::instances(ProviderEnum::ZApi)->me($uid, $token);
// Retorna: ['phone' => '...', 'name' => '...', 'avatar' => '...', 'locale' => 'es', 'isBusiness' => true]
```

## Limitaciones de Funapi

Funapi (Whatsmeow Bridge) tiene las siguientes **limitaciones conocidas**:

### ❌ No Soportado
- `checkPhone()` - No existe endpoint equivalente

### ⚠️ En Desarrollo
Los siguientes métodos retornan error descriptivo indicando que están en desarrollo:
- `sendButtons()`
- `sendButtonLink()`
- `sendOptionList()`
- `sendPoll()`
- `sendLink()`
- `sendEvent()`
- `sendTemplate()`

### ✅ Totalmente Funcional
- Mensajería básica: texto, archivos, ubicación, contactos
- Gestión de instancias completa
- Operaciones de grupos completas
- Cola de mensajes completa

## Manejo de Errores

Todos los métodos retornan arrays con:
- Éxito: `['messageId' => '...', 'status' => 'queued', ...]`
- Error: `['error' => 'mensaje descriptivo']`

```php
$response = WapiGateway::messages(ProviderEnum::ZApi)->sendText(...);

if (isset($response['error'])) {
    // Error normalizado del proveedor
    Log::error('Error enviando mensaje', ['error' => $response['error']]);
    return response()->json(['error' => $response['error']], 409);
}

// Éxito
return response()->json($response);
```

## Integración en Controladores

```php
use Funnelchat\WapiGateway\Facades\WapiGateway;
use Funnelchat\WapiGateway\Enums\ProviderEnum;

class WhatsAppController extends Controller
{
    public function send(Request $request)
    {
        $provider = match($request->provider) {
            'zapi' => ProviderEnum::ZApi,
            'uazapi' => ProviderEnum::Uazapi,
            'funapi' => ProviderEnum::Funapi,
            'meta' => ProviderEnum::WhatsAppCloud,
        };

        $response = WapiGateway::messages($provider)
            ->sendText(
                $request->uid,
                $request->header('token'),
                $request->phone,
                $request->message,
                ['retry' => true]
            );

        return isset($response['error'])
            ? response()->json(['error' => $response['error']], 409)
            : response()->json($response);
    }
}
```

## Changelog

### v0.3.0 - 2025-XX-XX (En desarrollo)

**Added:**
- ✨ Soporte completo para Newsletters/Canales: `createNewsletter()`, `updateNewsletterName()`, `updateNewsletterDescription()`, `updateNewsletterPicture()`, `newsletters()`, `newsletterMetadata()`
- ✨ Soporte para Comunidades: `community()`, `communities()`, `communitiesMetadata()`
- ✨ Sección de Contactos: `contact()`, `contacts()`, `sendContact()`, `addContacts()`
- ✨ Métodos de grupos: `adGroups()`, `groupInvitationMetadata()`, `groupInvitationLink()`, `lightGroupMetadata()`, `groupMetadata()`
- ✨ Método `pinMessage()` en MessagesContract

### v0.2.0 - 2025-01-XX

**Added:**
- ✨ Configuración automática de webhooks al crear instancias
- ✨ Variable de entorno `WEBHOOK_BASE_URL` para configuración centralizada
- ✨ Método privado `configureWebhooks()` en UazapiClient con logging completo
- ✨ Soporte para deshabilitar webhooks con `UAZAPI_AUTO_CONFIGURE_WEBHOOKS=false`

**Changed:**
- 🔧 ZApiClient, UazapiClient y FunapiClient ahora usan `WEBHOOK_BASE_URL` en lugar de `APP_URL`
- 🔧 Webhooks solo se configuran si `WEBHOOK_BASE_URL` está definida

### v0.1.0 - 2025-01-07

**Added:**
- ✨ Sistema completo de Resources para transformación de respuestas
- ✨ Soporte para Funapi (Whatsmeow Bridge) - 36/44 métodos funcionales
- ✨ Fetch automático de QR code en método `status()` cuando la instancia está desconectada
- ✨ Normalización de respuestas entre proveedores (accountStatus, result, locale, etc.)
- ✨ 20 Resources implementados (10 Z-API + 10 UAZAPI)
- ✨ Administración completa de templates para Meta/WhatsApp Cloud
- ✨ Método `deleteMessagesConcurrently()` para eliminación paralela de mensajes
- ✨ Retry automático configurable en métodos de envío
- ✨ Procesamiento asíncrono automático para videos
- ✨ Timeouts configurables por proveedor

**Fixed:**
- 🐛 Corregidos endpoints de Funapi (`/send-image` y `/send-document` sin sufijos)
- 🐛 Métodos no soportados de Funapi ahora retornan errores descriptivos

**Changed:**
- 🔧 Timeout por defecto reducido de 120s a 29s (configurable)
- 🔧 Retry inteligente: no reintenta en timeouts, solo en ConnectionException

**Performance:**
- ⚡ Eliminación de 100 mensajes: ~2s con HTTP Pool vs ~30s+ secuencial
- ⚡ Reducción de timeouts en envíos de videos gracias a procesamiento async

## Roadmap

- [ ] Completar endpoints faltantes de Funapi (botones, listas, encuestas)
- [ ] Tests automatizados para todos los proveedores
- [ ] Documentación de DTOs y respuestas por operación
- [x] Soporte para webhooks (configuración automática implementada)
- [ ] v1.0.0 - Release estable cuando todos los proveedores estén 100% completos

## Contribución

Esta es una librería privada de Funnelchat. Para reportar bugs o solicitar features, contacta a: soporte@funnelchat.io

## Licencia

Privado (Funnelchat). Todos los derechos reservados.
