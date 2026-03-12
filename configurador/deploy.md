# Voltika - 구현 계획서 (Deploy Plan)

## 개요
5개 디자인 이미지(add/1~5.jpeg) + detail.txt 기반으로 구현해야 할 화면들

---

## 1. paso-credito-enganche.js — 엔간체 결제 화면 재설계
**파일**: `js/modules/paso-credito-enganche.js`
**상태**: ⬜ 미구현
**디자인**: `add/1.jpeg`

### 현재 상태
- Stripe 카드폼만 있는 단순한 결제 화면

### 구현 내용
- [ ] 헤더: ✅ 아이콘 + "Tu Voltika está lista" + "Paga tu **enganche** para reservarla."
- [ ] 모토 요약 카드:
  - 모델명 헤더 (예: "Voltika M05")
  - 왼쪽: 모토 이미지
  - 오른쪽: Modelo, Color, Pago semanal, Plazo (주 단위), Entrega estimada, 배송 위치
- [ ] "ENGANCHE A PAGAR" 섹션: 큰 금액 표시 ($35,000 MXN)
- [ ] "Selecciona el método de pago" 헤딩
- [ ] **결제 방법 1 — Tarjeta de crédito/débito**:
  - 카드 아이콘 + VISA/Mastercard/AMEX 로고
  - "PAGAR CON TARJETA" 파란 버튼 → Stripe 카드 결제 진행
- [ ] **결제 방법 2 — Pago en efectivo en tiendas OXXO**:
  - OXXO 로고
  - $10,000 한도로 참조번호 자동 분할 (예: $35,000 = 3×$10,000 + 1×$5,000)
  - 각 참조번호 행: OXXO 로고 + "Referencia N: $X,XXX.XX" + 복사 아이콘
  - "PAGO EN EFECTIVO EN OXXO" 파란 버튼
- [ ] ~~SPEI 제거~~ (디자인에서 X 표시)
- [ ] 푸터: "✅ Pago 100% seguro · Confirmación inmediata"

### 기술 참고
- Stripe card payment는 기존 로직 유지 (PaymentIntent)
- OXXO 결제는 Stripe OXXO payment method 사용 또는 커스텀 참조번호 생성
- 금액 분할 로직: `Math.ceil(enganche / 10000)` 개의 참조번호

---

## 2. paso-credito-contrato.js — 계약 확인 화면 재설계
**파일**: `js/modules/paso-credito-contrato.js`
**상태**: ⬜ 미구현
**디자인**: `add/2.jpeg`

### 현재 상태
- 캔버스 서명 + 계약서 텍스트 (완전히 바뀜)

### 구현 내용
- [ ] 진행 바 (상단):
  - ✓ Solicitud enviada | ✓ Identidad verificada | ✓ Enganche recibido | → Paso final
  - 파란 프로그래스 바 (약 90% 채움)
- [ ] 제목: "🎉 ¡Tu Voltika está apartada!"
- [ ] 부제: "Tu enganche de $XX,XXX MXN fue recibido correctamente."
- [ ] **파란 배너 카드**: "🚀 ¡Tu financiamiento Voltika fue aprobado!"
  - 왼쪽: 모토 이미지 + 캘린더 위젯 (배송 예상일)
  - 오른쪽:
    - "📍 Tu moto está **reservada** y comenzaremos a preparar tu entrega."
    - "✅ Un asesor **Voltika** te contactará en máximo **48 horas** para:"
    - • Confirmar el punto de entrega
    - • Coordinar fecha y horario
    - • Resolver cualquier duda
- [ ] **계약 서명 섹션**:
  - "✅ Para **finalizar** tu crédito, confirma tu número:"
  - "Código enviado a: +52 55 XXXX XXXX" (마스킹된 전화번호)
  - 6자리 OTP 입력 박스 (auto-advance, backspace, paste 지원)
  - "Reenviar código" 링크
  - 체크박스: "✅ **Acepto y firmo** electrónicamente el Contrato de Financiamiento **Voltika** [Ver contrato]"
  - 파란 버튼: "Confirmar mi financiamiento"
  - "⏱ Esto toma menos de 10 segundos"
- [ ] 신뢰 항목:
  - "✓ Tu crédito inicia cuando **recibes** tu **Voltika**"
  - "✓ Puedes pagar antes o adelantar pagos cuando quieras, sin penalización"
- [ ] 보안 박스: "🔑 Tu teléfono será la **llave de seguridad** de tu entrega"
  - "El día de la entrega enviaremos un código **SMS** para autorizar la entrega de tu **Voltika**."
- [ ] 푸터: "En el siguiente paso podrás configurar tu método de pago automático para tu **crédito Voltika**."

### 기술 참고
- 기존 서명 캔버스 로직 제거
- OTP 로직은 `paso-credito-consentimiento.js`의 기존 OTP 패턴 재사용
- 전화번호 마스킹: `state.telefono`에서 마지막 4자리만 표시
- AJAX: `php/enviar-otp.php` (기존), `php/confirmar-contrato.php` (필요시 생성)

---

## 3. paso-credito-pagos-automaticos.js — 자동 결제 등록 (신규)
**파일**: `js/modules/paso-credito-pagos-automaticos.js` (신규 생성)
**상태**: ⬜ 미구현
**디자인**: `add/3.jpeg`

### 현재 상태
- 파일 없음 (완전히 새로 생성)

### 구현 내용
- [ ] Voltika 로고 (상단)
- [ ] 제목: "Activa tu pago automático"
- [ ] 부제: "Configura el método de pago para tu crédito Voltika."
- [ ] 3개 그린 체크마크 혜택:
  - "✅ Se activará solo cuando recibas tu Voltika"
  - "✅ No tendrás que recordar pagos"
  - "✅ Puedes adelantar pagos cuando quieras"
- [ ] 보안 정보 박스 (회색 배경):
  - "🛡 **No se realizará ningún** cargo ahora"
  - "Tu pago automático solo se activará cuando recibas y aceptes tu Voltika en la entrega."
- [ ] Stripe 카드 등록 폼 (Setup Intent):
  - 💳 Número de tarjeta (전체 너비)
  - 📅 MM / AA (왼쪽) + 🔒 CVV (오른쪽)
- [ ] 파란 버튼: "Activar mis pagos"
- [ ] 하단 신뢰 정보:
  - "🔒 Sin cargos antes de tu entrega"
  - "💡 **Podrás cambiar tu tarjeta** cuando quieras desde tu cuenta Voltika."

### 기술 참고
- Stripe Setup Intent (카드 저장, 즉시 청구 안 함)
- `stripe.confirmCardSetup()` 사용
- 새 PHP 엔드포인트 필요: `php/create-setup-intent.php`
- 메인 앱(`configurador.js`)에 `credito-pagos-automaticos` 스텝 등록 필요
- 컨테이너 ID: `#vk-credito-pagos-automaticos-container`

---

## 4. paso-exito.js — 크레딧 완료 화면 (크레딧 플로우)
**파일**: `js/modules/paso-exito.js` (수정 또는 분기)
**상태**: ⬜ 미구현
**디자인**: `add/4.jpeg`

### 구현 내용 (크레딧 고객용)
- [ ] 제목: "¡Listo! Tu Voltika fue apartada 🚀"
- [ ] 색종이 confetti 효과 (CSS 또는 이미지)
- [ ] 메인 비주얼:
  - 모토 이미지 + Voltika 어드바이저 캐릭터 (3D 렌더 이미지 필요)
  - 녹색 WhatsApp 체크 배지
  - 어드바이저가 Voltika 폰/클립보드 들고 있는 이미지
- [ ] 텍스트: "En máximo **48 horas**, un asesor **Voltika** te contactara para coordinar la entrega. Avisanos si cambias de número o email."
- [ ] 정보 박스:
  - "✅ Te contactaremos **por WhatsApp o email**"
  - "🔔 **Mantente** pendiente de nuestros mensajes"
- [ ] "✅ **¡Felicidades!** Próximamente recibirás tu moto eléctrica **Voltika**. Gracias por confiar en nosotros 😊"
- [ ] 파란 버튼: "Entendido"
- [ ] 푸터: "Voltika siempre contigo"
- [ ] 연락처: "WhatsApp +52 55 1341 6370 · ventas@voltika.mx"
- [ ] 도움말: "Si necesitas ayuda, contáctanos al +52 55 1341 6370"

### 필요한 이미지
- 어드바이저 캐릭터 이미지 (3D 렌더) → `img/` 폴더에 업로드 필요
- 또는 기존 `img/asesor_icon.jpg` 활용

---

## 5. paso-exito.js — 현금/MSI 완료 화면 (컨타도 플로우)
**파일**: `js/modules/paso-exito.js` (수정 또는 분기)
**상태**: ⬜ 미구현
**디자인**: `add/5.jpeg`

### 구현 내용 (현금/MSI 고객용)
- [ ] 녹색 체크마크 아이콘 (큰 원형) + 로켓 🚀 + confetti
- [ ] 제목: "¡Compra confirmada!"
- [ ] 부제: "Tu **Voltika** ya está en preparación para entrega."
- [ ] 어드바이저 연락 안내:
  - "En máximo **48 horas**, un asesor **Voltika** te contactará para:"
  - ✓ **Confirmar** el punto de **entrega**
  - ✓ **Coordinar** fecha y **horario**
  - ✓ Resolver cualquier duda
- [ ] 보안 박스 (회색 배경):
  - "🔒 Tu teléfono será tu **llave de seguridad**"
  - "Para proteger tu compra, la entrega de tu Voltika se autoriza **únicamente** con un código **SMS** enviado a tu teléfono."
  - "El día de la entrega recibirás un **código de confirmación** que deberás mostrar para recibir tu moto."
- [ ] 정보 박스:
  - "✅ Te contactaremos **por WhatsApp o email**"
  - "🔔 **Mantente** pendiente de nuestros mensajes"
- [ ] "✅ **¡Felicidades!** Próximamente recibirás tu **Voltika**. Gracias por confiar en nosotros 😊"
- [ ] 파란 버튼: "Entendido"
- [ ] 연락처: "WhatsApp +52 55 1341 6370 · ventas@voltika.mx"

---

## 6. 메인 앱 변경 (configurador.js)
**파일**: `js/configurador.js`
**상태**: ⬜ 미구현

### 구현 내용
- [ ] 새 스텝 등록: `credito-pagos-automaticos`
- [ ] HTML 컨테이너 추가: `<div id="vk-credito-pagos-automaticos-container">`
- [ ] 플로우 변경:
  - 크레딧: ... → `credito-enganche` → `credito-contrato` → `credito-pagos-automaticos` → `exito`
  - 현금/MSI: ... → `exito` (기존 유지, 화면만 변경)
- [ ] `paso-exito.js`에서 `state.metodoPago`에 따라 크레딧/현금 화면 분기
- [ ] 새 JS 파일 로드 (`paso-credito-pagos-automaticos.js`)

---

## 7. 필요한 이미지/에셋
**상태**: ⬜ 미확인

- [ ] 어드바이저 캐릭터 이미지 (Image 4의 3D 캐릭터) → `img/asesor_3d.png` 또는 유사
- [ ] OXXO 로고 이미지 → `img/oxxo_logo.png`
- [ ] VISA/Mastercard/AMEX 로고 (기존 `VkUI.renderCardLogos()` 확인)
- [ ] confetti 효과 (CSS animation 또는 이미지)
- [ ] 캘린더 아이콘/위젯 (Image 2)

---

## 8. PHP 백엔드 (필요시)
**상태**: ⬜ 미구현

- [ ] `php/create-setup-intent.php` — Stripe Setup Intent 생성 (자동 결제 카드 등록용)
- [ ] `php/confirmar-contrato.php` — 계약 OTP 확인 + 전자 서명 저장
- [ ] OXXO 결제 참조번호 생성 로직 (Stripe OXXO 또는 커스텀)

---

## 플로우 요약

```
[크레딧 플로우]
paso1 → paso2 → paso3 → credito-ingresos → credito-consentimiento
→ credito-loading → credito-aprobado → credito-identidad → credito-resultado
→ credito-pago → credito-enganche (재설계) → credito-contrato (재설계)
→ credito-pagos-automaticos (신규) → exito (크레딧 버전)

[현금/MSI 플로우]
paso1 → paso2 → paso3 → paso4/paso4a → exito (현금 버전)
```

---

*생성일: 2026-03-12*
*마지막 업데이트: 2026-03-12*
