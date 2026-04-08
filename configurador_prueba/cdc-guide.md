# Círculo de Crédito — Production Setup Guide

This guide walks you through connecting Voltika to the real Círculo de Crédito API (production).

Currently the system uses a sandbox/test API. After completing these steps, credit checks will return real bureau scores from actual customers.

---

## Prerequisites

- Access to the Círculo de Crédito developer portal
- Portal credentials: `ivan.clavel@voltika.mx` / `Meusnier18&%`
- All steps must be completed in order

---

## Step 1: Generate New Certificate

The previous certificate used the wrong encryption type. We need to regenerate it with ECDSA secp384r1 (required by CDC).

**Action:** Open this URL in your browser:

```
https://www.voltika.mx/configurador_prueba/php/generar-certificado-cdc.php?key=voltika_cdc_cert_2026&regen=1
```

**What you'll see:**
- A green success message: "✅ Certificado ECDSA secp384r1 generado"
- Certificate details table (Organization: Voltika MX, Type: ECDSA secp384r1)

**What to do:**
1. Click the blue "📥 Descargar cdc_certificate.pem" button to download the certificate file
2. If the download button doesn't work, click the text box to copy the certificate content, then:
   - Open Notepad (or any text editor)
   - Paste the content
   - Save as `cdc_certificate.pem` (make sure it saves as `.pem`, not `.pem.txt`)

**Keep this file** — you'll need it in the next step.

---

## Step 2: Log into CDC Developer Portal

**Action:**
1. Go to https://developer.circulodecredito.com.mx
2. Click "Iniciar sesión" or "Login"
3. Enter credentials:
   - Username: `ivan.clavel@voltika.mx`
   - Password: `Meusnier18&%`

---

## Step 3: Upload Your Certificate to CDC

After logging in, you need to upload the certificate file you downloaded in Step 1.

**Action:**
1. Navigate to the certificates section in the API Hub
   - Look for "Certificados" or "Mis certificados" in the menu
   - Or follow the guide at: https://developer.circulodecredito.com.mx/guia_de_inicio (Step 4)
2. Click "Subir certificado" or "Upload certificate"
3. Select the `cdc_certificate.pem` file you downloaded
4. Confirm the upload

**Expected result:** The portal shows your certificate as uploaded/active.

---

## Step 4: Download CDC's Certificate

After uploading your certificate, CDC provides their own certificate for you to use.

**Action:**
1. In the same certificates section, look for "Descargar certificado de Círculo de Crédito" or similar
2. Download CDC's certificate file (it might be called `cdc_cert.pem` or similar)
3. **Send this file to me** — I need to install it on the server

**Important:** This is CDC's certificate, NOT the one you uploaded. It's a different file that CDC provides to you.

---

## Step 5: Enable SecurityTest API

Before running the security test, you may need to enable the SecurityTest API in your account.

**Action:**
1. In the portal, go to "APIs" or "Mis APIs"
2. Look for "SecurityTest" or "Prueba de Seguridad"
3. If it's not enabled, click "Suscribirse" or "Enable"
4. Make sure it shows as active

---

## Step 6: Run the Security Test

This is the step where CDC verifies that your certificate and signing are working correctly.

**You don't need to do anything manually** — I've created an automatic script that handles everything.

**Action:** Open this URL in your browser:

```
https://www.voltika.mx/configurador_prueba/php/cdc-security-test.php?key=voltika_cdc_cert_2026
```

**What the script does automatically:**
1. Loads your private key
2. Signs a test message ("Esto es un mensaje de prueba")
3. Sends it to CDC's SecurityTest API
4. Shows you the result

**What you'll see:**

If everything is correct:
```
✅ Paso 1: Llave privada cargada (ECDSA)
✅ Paso 2: Mensaje firmado correctamente
✅ Paso 3: Respuesta recibida exitosamente
🎉 Prueba de seguridad completada
```

If there's an error:
```
❌ Acceso denegado (HTTP 401)
```
This means the certificate hasn't been uploaded yet, or the SecurityTest API isn't enabled in your account.

**Take a screenshot of the result and send it to me** so I can verify.

---

## Step 7: Request Production Access

After the security test passes, you can request production access.

**Action:**
1. Go to: https://developer.circulodecredito.com.mx/pase_a_produccion
2. Fill out the production access request form
3. Submit the request

**Expected timeline:** CDC usually responds within 1-5 business days.

**After approval, CDC will provide:**
- Production API key
- Production endpoint URL (without `/sandbox/`)

**Send both to me** and I will update the code to use the real API.

---

## Summary Checklist

| Step | Action | Status |
|------|--------|--------|
| 1 | Generate new ECDSA certificate | ⬜ |
| 2 | Log into CDC portal | ⬜ |
| 3 | Upload certificate to CDC | ⬜ |
| 4 | Download CDC's certificate → send to me | ⬜ |
| 5 | Enable SecurityTest API | ⬜ |
| 6 | Run security test → send screenshot | ⬜ |
| 7 | Request production access | ⬜ |
| 8 | Receive production credentials → send to me | ⬜ |
| 9 | I update the code (final step) | ⬜ |

---

## Troubleshooting

**"Certificate not found" error:**
→ Go back to Step 1 and regenerate the certificate.

**Download button doesn't work:**
→ Use the copy/paste method: click the text box, paste into Notepad, save as `.pem`.

**Security test returns 401/403:**
→ Make sure you uploaded the certificate (Step 3) and enabled SecurityTest API (Step 5).

**Security test returns connection error:**
→ The certificate might not be uploaded yet, or CDC's service might be temporarily down. Wait and try again.

**"La firma no coincide" error:**
→ You may have uploaded an old certificate. Regenerate (Step 1 with `&regen=1`) and re-upload.

---

## Quick Links

| Page | URL |
|------|-----|
| Generate certificate | `https://www.voltika.mx/configurador_prueba/php/generar-certificado-cdc.php?key=voltika_cdc_cert_2026` |
| Regenerate certificate | `https://www.voltika.mx/configurador_prueba/php/generar-certificado-cdc.php?key=voltika_cdc_cert_2026&regen=1` |
| Run security test | `https://www.voltika.mx/configurador_prueba/php/cdc-security-test.php?key=voltika_cdc_cert_2026` |
| CDC portal | `https://developer.circulodecredito.com.mx` |
| CDC guide | `https://developer.circulodecredito.com.mx/guia_de_inicio` |
| CDC security test page | `https://developer.circulodecredito.com.mx/prueba_de_seguridad` |
| CDC production request | `https://developer.circulodecredito.com.mx/pase_a_produccion` |

---

## What I Need From You

After completing the steps above, please send me:

1. **CDC's certificate file** (downloaded in Step 4)
2. **Security test screenshot** (from Step 6)
3. **Production API key + endpoint URL** (after Step 7 is approved)

Once I have these, I will complete the final code update and the real credit bureau checks will be live.
