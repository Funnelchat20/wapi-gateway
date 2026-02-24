#!/bin/bash

BASE_URL="http://homolog-whatsmeow-alb-731186848.us-east-1.elb.amazonaws.com"
CLIENT_TOKEN="iB4uIxOYOMFSnScXWlphBg=="
UID="eb77158b-0be7-409c-a742-72bc36ee4596"
TOKEN="620a2169-8438-4a78-8db4-a57eaa09d447"

echo "=== Test de QR Code ==="
echo ""
echo "Obteniendo QR code de la instancia..."
echo "UID: ${UID}"
echo ""

curl -X GET "${BASE_URL}/instances/${UID}/token/${TOKEN}/qr-code/image" \
  -H "Client-Token: ${CLIENT_TOKEN}" \
  -H "Accept: application/json" \
  -w "\n\nHTTP Status: %{http_code}\n" \
  -s | jq . 2>/dev/null || cat

echo ""
echo "=== Fin del test ==="
