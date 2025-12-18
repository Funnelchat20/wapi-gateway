#!/bin/bash

echo "=== Test de Creación de Instancia Funapi con cURL ==="
echo ""

BASE_URL="http://homolog-whatsmeow-alb-731186848.us-east-1.elb.amazonaws.com"
TOKEN="iB4uIxOYOMFSnScXWlphBg=="
USER_ID=1
DEVICE_ID=999

ENDPOINT="${BASE_URL}/instances/integrator/on-demand"

echo "Endpoint: ${ENDPOINT}"
echo "Token: ${TOKEN:0:10}..."
echo "User ID: ${USER_ID}"
echo "Device ID: ${DEVICE_ID}"
echo ""
echo "Enviando request..."
echo ""

curl -X POST "${ENDPOINT}" \
  -H "Authorization: Bearer ${TOKEN}" \
  -H "Content-Type: application/json" \
  -d "{
    \"name\": \"U-${USER_ID} D-${DEVICE_ID}\",
    \"sessionName\": \"Funnelchat\",
    \"receivedCallbackUrl\": \"http://localhost/webhooks/funapi/received?userId=${USER_ID}&deviceId=${DEVICE_ID}\",
    \"receivedAndDeliveryCallbackUrl\": \"http://localhost/webhooks/funapi/received-and-delivery?userId=${USER_ID}&deviceId=${DEVICE_ID}\",
    \"disconnectedCallbackUrl\": \"http://localhost/webhooks/funapi/disconnected?userId=${USER_ID}&deviceId=${DEVICE_ID}\",
    \"connectedCallbackUrl\": \"http://localhost/webhooks/funapi/connected?userId=${USER_ID}&deviceId=${DEVICE_ID}\",
    \"messageStatusCallbackUrl\": \"http://localhost/webhooks/funapi/message-status?userId=${USER_ID}&deviceId=${DEVICE_ID}\",
    \"blockCallbackUrl\": \"http://localhost/webhooks/funapi/block?userId=${USER_ID}&deviceId=${DEVICE_ID}\"
  }" \
  -w "\n\nHTTP Status: %{http_code}\n" \
  -v 2>&1 | tee test-result.txt

echo ""
echo "=== Fin del test ==="
echo ""
echo "Resultado guardado en test-result.txt"
