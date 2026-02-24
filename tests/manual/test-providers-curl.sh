#!/bin/bash

echo "=== PRUEBA DE STATUS EN LOS 3 PROVEEDORES ==="
echo ""

# Credenciales
FUNAPI_BASE="http://homolog-whatsmeow-alb-731186848.us-east-1.elb.amazonaws.com"
FUNAPI_UID="eb77158b-0be7-409c-a742-72bc36ee4596"
FUNAPI_TOKEN="620a2169-8438-4a78-8db4-a57eaa09d447"
FUNAPI_CLIENT_TOKEN="iB4uIxOYOMFSnScXWlphBg=="

ZAPI_UID="3EBCAF7A99F7302BD20EDAD09DD89927"
ZAPI_TOKEN="82FE6A7C7A9543D2AEF2E0EE8A20BD17"
ZAPI_CLIENT_TOKEN="F6a40d22236054786ad4e761cb766c5d7S"

UAZAPI_TOKEN="03ee4f7a-07e6-4da8-ba23-b92544670c07"
UAZAPI_BASE="https://funnelchat.uazapi.com"

PHONE="5493764901973"

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "📱 FUNAPI STATUS (instancia desconectada)"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""
curl -s -X GET "${FUNAPI_BASE}/instances/${FUNAPI_UID}/token/${FUNAPI_TOKEN}/status" \
  -H "Client-Token: ${FUNAPI_CLIENT_TOKEN}" \
  -H "Accept: application/json" | jq .
echo ""
echo ""

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "📱 ZAPI STATUS"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""
curl -s -X GET "https://api.z-api.io/instances/${ZAPI_UID}/token/${ZAPI_TOKEN}/status" \
  -H "Client-Token: ${ZAPI_CLIENT_TOKEN}" \
  -H "Accept: application/json" | jq .
echo ""
echo ""

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "📱 UAZAPI STATUS"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""
curl -s -X GET "${UAZAPI_BASE}/instance/status" \
  -H "token: ${UAZAPI_TOKEN}" \
  -H "Accept: application/json" | jq .
echo ""
echo ""

echo "=== NOTA ==="
echo "Estas son las respuestas RAW de las APIs."
echo "El SDK debe transformarlas para que TODAS incluyan:"
echo "  - accountStatus: 'authenticated' | 'got qr code' | 'connecting' | 'disconnected'"
echo "  - qrCode: (si no está conectado)"
echo ""
