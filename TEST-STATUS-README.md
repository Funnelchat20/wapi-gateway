# Test de Paridad del Endpoint STATUS

Este script prueba que todos los proveedores del SDK wapi-gateway se comporten de manera consistente en el endpoint `status()`.

## Objetivo

Verificar que todos los proveedores:
- ✅ Retornan el campo `accountStatus` en sus respuestas
- ✅ Normalizan errores de forma consistente
- ✅ Manejan estados: `authenticated`, `got qr code`, `disconnected`, etc.
- ✅ Obtienen automáticamente el QR code cuando no están conectados

## Ejecución

```bash
php test-status-parity.php
```

## Configuración de Credenciales

Para probar con instancias reales, exporta las siguientes variables de entorno:

### Funapi (Whatsmeow Bridge)
```bash
export FUNAPI_BASE_URL="http://homolog-whatsmeow-alb-731186848.us-east-1.elb.amazonaws.com"
export FUNAPI_CLIENT_TOKEN="iB4uIxOYOMFSnScXWlphBg=="
export FUNAPI_UID="eb77158b-0be7-409c-a742-72bc36ee4596"
export FUNAPI_TOKEN="620a2169-8438-4a78-8db4-a57eaa09d447"
```

### ZApi
```bash
export ZAPI_CLIENT_TOKEN="TU_CLIENT_TOKEN_AQUI"
export ZAPI_UID="3EBCAF7A99F7302BD20EDAD09DD89927"
export ZAPI_TOKEN="82FE6A7C7A9543D2AEF2E0EE8A20BD17"
```

### Uazapi
```bash
export UAZAPI_BASE_URL="https://funnelchat.uazapi.com"
export UAZAPI_UID="TU_UID_AQUI"
export UAZAPI_TOKEN="TU_TOKEN_AQUI"
```

### Meta (WhatsApp Cloud)
```bash
export META_UID="TU_UID_AQUI"
export META_TOKEN="TU_TOKEN_AQUI"
```

## Resultados Esperados

### Instancia Autenticada
```json
{
  "connected": true,
  "smartphoneConnected": true,
  "accountStatus": "authenticated"
}
```

### Instancia No Conectada (con QR)
```json
{
  "connected": false,
  "accountStatus": "got qr code",
  "value": "data:image/png;base64,..."
}
```

### Error Normalizado
```json
{
  "error": "instance_not_found"
}
```

## Interpretación de Resultados

El script muestra:
1. **Response individual de cada proveedor** con tiempo de respuesta
2. **Matriz de comparación** mostrando qué campos retorna cada uno
3. **Resumen** con estadísticas de éxito

### Símbolos
- ✅ `SUCCESS` - Respuesta exitosa sin errores
- ❌ `ERROR` - Respuesta con campo error
- 💥 `EXCEPTION` - Excepción capturada (problema de conexión/código)
- 🎯 Campo presente en la respuesta
- ❌ N/A - Campo no presente

## Próximos Tests

Crear scripts similares para otros endpoints:
- `test-qrcode-parity.php` - Endpoint de QR Code
- `test-send-text-parity.php` - Envío de mensajes de texto
- `test-send-file-parity.php` - Envío de archivos
- `test-logout-parity.php` - Logout de instancias
- `test-create-instance-parity.php` - Creación de instancias

## Notas

- **Meta (WhatsApp Cloud)**: No soporta operaciones de instancias (retorna "Not supported")
- **Funapi**: Debe agregar `accountStatus` incluso en respuestas de error para compatibilidad WAPI
- **ZApi/Uazapi**: Necesitan credenciales válidas para pruebas completas
