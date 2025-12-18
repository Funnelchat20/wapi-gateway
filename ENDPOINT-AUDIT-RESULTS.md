# 📋 Auditoría Completa de Endpoints - WAPI vs wapi-gateway

**Fecha:** 2025-12-16 16:30 UTC

---

## 🔴 ENDPOINTS CRÍTICOS

### 1. ✅ status() - CORREGIDO
**Campos WAPI:**
```json
{
  "accountStatus": "authenticated",
  "qrCode": ""
}
```
**wapi-gateway:** ✅ YA CORRECTO

---

### 2. ⚠️ qrCode() - NECESITA CORRECCIÓN
**Campos WAPI (Zapi):**
```json
{
  "connected": false,
  "qrCode": "data:image/..."
}
```

**Campos WAPI (Uazapi):**
```json
{
  "connected": false,
  "qrCode": "data:image/..."
}
```

**wapi-gateway actual (ZApi):**
```php
// src/Clients/ZApiClient.php - qrCode()
return QrCodeResource::make($res->json());
```

**src/Resources/Zapi/QrCodeResource.php:**
```php
return [
    "value" => $data['value'] ?? ""
];
```

❌ **PROBLEMA:** Solo retorna `value`, debería retornar `connected` + `qrCode`

---

### 3. ⚠️ me() - NECESITA VERIFICACIÓN
**Campos WAPI (Zapi + Uazapi):**
```json
{
  "phone": "5493764901973",
  "locale": "",
  "name": "fundev",
  "avatar": "https://...",
  "isBusiness": false
}
```

**wapi-gateway actual:** 
```php
// Implementado en Resources, verificar si coincide
```

---

### 4. ⚠️ sendText() / MessageResource - NECESITA CORRECCIÓN
**Campos WAPI (ambos):**
```json
{
  "sent": true,
  "message": "",
  "id": "3EB0...",
  "queueNumber": ""
}
```

**wapi-gateway actual (Uazapi):**
```php
// Ya corregido con 'messageid'
return [
    "sent" => !empty($messageId),
    "message" => "",
    "messageId" => $messageId,  // ❌ Debería ser "id"
    "id" => $messageId,
    "phone" => $data['chatid'] ?? "",  // ❌ Campo extra
    "queueNumber" => ""
];
```

❌ **PROBLEMA:** Campo `phone` extra, campo `messageId` extra

---

### 5. ⚠️ checkPhone() - NECESITA CORRECCIÓN
**Campos WAPI (ambos):**
```json
{
  "result": true
}
```

**wapi-gateway actual:**
```php
// Verificar implementación actual
```

---

### 6. ⚠️ logout() - NECESITA CORRECCIÓN  
**Campos WAPI (ambos):**
```json
{
  "result": "Logout request sent to WhatsApp"
}
```

**wapi-gateway actual:**
```php
// src/Resources/Zapi/LogOutResource.php
return LogOutResource::make($res->json());
```

---

### 7. ⚠️ reboot() - NECESITA CORRECCIÓN
**Campos WAPI (ambos):**
```json
{
  "success": true
}
```

---

## 📊 Resumen de Correcciones Necesarias

| Endpoint | Status Actual | Campos Faltantes | Campos Extra | Acción |
|----------|---------------|------------------|--------------|--------|
| status() | ✅ CORRECTO | - | - | - |
| qrCode() | ❌ Incorrecto | connected, qrCode | value | Corregir Resource |
| me() | 🔍 Verificar | - | ? | Validar |
| sendText() | ⚠️ Parcial | - | phone, messageId | Eliminar extras |
| checkPhone() | 🔍 Verificar | - | ? | Validar |
| logout() | 🔍 Verificar | - | ? | Usar Resource |
| reboot() | 🔍 Verificar | - | ? | Usar Resource |

---

## 🔧 Plan de Corrección

### PASO 1: Corregir QrCodeResource
```php
// src/Resources/Zapi/QrCodeResource.php
public static function make(array $data): array
{
    return [
        'connected' => $data['connected'] ?? false,
        'qrCode' => $data['value'] ?? $data['qrcode'] ?? '',
    ];
}
```

### PASO 2: Corregir MessageResource (Uazapi)
```php
// Ya tiene la estructura correcta en WAPI original:
return [
    "sent" => isset($this['messageId']) || isset($this['id']),
    "message" => "",
    "id" => $this['messageId'] ?? $this['id'] ?? "",
    "queueNumber" => ""
];
```

Eliminar campos `phone` y `messageId` duplicado.

### PASO 3: Implementar Resources faltantes
- LogOutResource
- RebootResource  
- CheckPhoneResource
- MeResource

---

## 📝 Notas de Implementación

### Patrón a Seguir
1. Ver Resource del WAPI original
2. Copiar campos exactos
3. Adaptar nombres de campos según proveedor
4. NO agregar campos extra
5. Retornar solo lo que WAPI retorna

### Particularidades Encontradas

**qrCode():**
- Retorna `connected` + `qrCode`
- ZApi: campo `value`
- Uazapi: campo `qrcode`

**me():**
- Retorna 5 campos fijos
- `locale` siempre vacío
- ZApi: `imgUrl`
- Uazapi: `profilePicUrl`

**sendText():**
- Retorna 4 campos fijos
- `message` siempre vacío
- `queueNumber` siempre vacío
- ZApi: `messageId`
- Uazapi: `messageid` o `id`

**logout():**
- Retorna solo `result` con mensaje estático
- ZApi: verifica `value`
- Uazapi: verifica `status === 'disconnected'`

**reboot():**
- Retorna solo `success` boolean
- ZApi: campo `value`
- Uazapi: campo `status === 'restarted'`

