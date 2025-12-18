# 🎯 Análisis Comparativo: wapi-gateway vs WAPI Original

**Fecha:** 2025-12-16  
**Objetivo:** Replicar exactamente el comportamiento del WAPI original

---

## 📌 Premisa del Proyecto

**wapi-gateway** debe ser una **réplica exacta del WAPI original**, con la adición del nuevo proveedor Funapi que debe **adaptarse** al comportamiento ya establecido por ZApi y Uazapi en el WAPI original.

---

## 🔍 Análisis del Endpoint `status()`

### WAPI Original - StatusResource

**Zapi/StatusResource.php:**
```php
return [
    "accountStatus" => $this['accountStatus'] ?? "",
    "qrCode" => $this['qrCode'] ?? "",
];
```

**Uazapi/StatusResource.php:**
```php
return [
    "accountStatus" => $this['accountStatus'] ?? "",
    "qrCode" => $this['qrCode'] ?? "",
];
```

**✅ COMPORTAMIENTO ESPERADO:**
- Solo 2 campos: `accountStatus` y `qrCode`
- Sin campos extra como `connected`, `error`, `session`, etc.

---

## ❌ Estado Actual - wapi-gateway

### ZApiClient::status() - INCORRECTO
```php
return $data;  // ← Retorna TODO el JSON de la API ZApi
```

**Retorna:**
```json
{
  "connected": false,
  "session": false,
  "created": 1765895191567,
  "error": "You are not connected.",
  "smartphoneConnected": false,
  "accountStatus": "got qr code",
  "qrCode": "data:image/png;base64,..."
}
```

### FunapiClient::status() - INCORRECTO
```php
return $data;  // ← Retorna TODO el JSON de la API Funapi
```

**Retorna:**
```json
{
  "connected": false,
  "error": "You need to restore the session.",
  "smartphoneConnected": false,
  "accountStatus": "got qr code",
  "qrCode": "data:image/png;base64,..."
}
```

### UazapiClient::status() - INCORRECTO
```php
return $normalizedData;  // ← Construye objeto con campos extra
```

**Retorna:**
```json
{
  "connected": false,
  "smartphoneConnected": false,
  "error": "401: logged out from another device",
  "accountStatus": "got qr code"
}
```

---

## ✅ Corrección Requerida

**TODOS los clientes deben retornar:**
```json
{
  "accountStatus": "got qr code",
  "qrCode": "data:image/png;base64,..."
}
```

**O cuando está conectado:**
```json
{
  "accountStatus": "authenticated",
  "qrCode": ""
}
```

---

## 🔧 Plan de Corrección

1. **ZApiClient::status()** - Filtrar respuesta
2. **FunapiClient::status()** - Filtrar respuesta  
3. **UazapiClient::status()** - Filtrar respuesta
4. **Crear StatusResource** compartido (opcional)
5. **Tests de regresión** contra WAPI original

