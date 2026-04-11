# Voltika Dashboard Diagram 분석

원본 파일: [information/dashboards_diagrams.pdf](information/dashboards_diagrams.pdf)

이 문서는 고객이 제공한 Voltika(전기 오토바이) 판매·배송 시스템의 업무 프로세스 플로우 다이어그램을 분석한 내용이다. 시스템은 5개의 패널(Purchase, Point, Inventory, Shipping, Client)과 이들 간의 상태 전이 및 알림 규칙으로 구성된다.

---

## 1. 구매 케이스 (Purchase Cases)

다이어그램은 4가지 구매 시나리오를 정의한다.

### CASE 1 — CODIGO REFERIDO 없는 구매
- Configurator에서 사용자가 Voltika 포인트를 선택하지 않은 경우
- Purchase Panel로 신규 주문이 들어오고 상태는 **"Purchases waiting point assignation"**
- 모터사이클 배정 대기 상태

### CASE 2 — 사용자가 Configurator에서 포인트를 직접 선택
- Configurator 내에서 포인트를 선택 → Purchase Panel에 "Purchases with point assigned"로 등록
- 결제 성공 시 재고에서 모터사이클 배정

### CASE 3 — CODIGO REFERIDO로 일반 판매 (General Sale)
- Voltika 포인트 코드 또는 인플루언서 코드로 구매
- `PUNTO VENTA GENERAL`로 신규 주문 생성
- 레퍼럴 코드 소유 포인트에 자동 배정 → Point Panel에 레퍼럴 완료 판매로 표시, 모터사이클 배정 대기

### CASE 4 — CODIGO REFERIDO로 쇼룸 판매 (Showroom Sale)
- 포인트의 쇼룸 재고에서 직접 판매
- `INVENTORY FOR SHOWROOM SALE` 상태로 배정
- 모델/색상이 주문과 일치해야 하며, free 상태의 모터사이클만 배정 가능

---

## 2. 패널 구조

### Purchase Panel
- 신규 주문 수신 (with/without CODIGO REFERIDO)
- 주문 상태: 포인트 배정 대기 / 모터사이클 배정 대기
- 결제 성공 시 인벤토리에서 모터사이클 할당

### Point Panel (Punto Voltika)
- **Inventario por Entrega**: 배송 대기 중인 모터사이클 (PENDIENTE DE ENVIO)
- **Inventario Showroom**: 쇼룸 진열/판매용 재고
- 신규 모터사이클 수령 → 체크리스트 작성 → QR 스캔 → 조립(Assembly)
- 조립 완료 후 상태를 `LISTA PARA ENTREGA`로 변경
- 사용자에게 픽업 가능 날짜 지정하여 알림 발송

### Inventory Panel — 모터사이클 상태 머신
```
PENDIENTE DE ENSAMBLE
   ↓
PENDIENTE DE ENVIO / INVENTARIO POR ENTREGA
   ↓
LISTA PARA ENTREGA
   ↓
EN INVENTARIO (쇼룸) / ENTREGADA (인도 완료)
```

### Shipping Panel
- 기존 주문에 대한 배송 생성 또는 주문 없이 모토 이동(`send a moto without order`)
- Skydrop API로 도착 예정일 자동 조회
- 배송 생성 시 사용자에게 포인트 주소·연락처·도착 예정일 알림 발송
- Point Panel에도 배송 정보 자동 업데이트

### Client Panel
- 포인트 배정 알림 (주소·연락처)
- 배송 시작 알림 (도착 예정일 포함)
- 픽업 가능 알림 (날짜 포함)

---

## 3. 배송·수령 프로세스 (Shipping & Reception)

1. **SHIPPING** 생성 — 기존 주문 연결 또는 주문 없이 발송
2. **POINT VOLTIKA PANEL** — 배송 정보 수신, 모터사이클 도착 대기
3. **RECEPTION PROCESS** — 포인트에서 QR 스캔, 모토 정보 취득, 수령 체크리스트 작성(패키지 사진 포함)
4. **Assembly** — 조립 완료
5. **Change status to LISTA PARA ENTREGA** — 포인트가 픽업 날짜 지정 후 사용자에게 알림

---

## 4. 인도 프로세스 (Delivery Process)

1. **Point Panel**: 배송 대기 주문 목록 확인
2. **Start Delivery** — 결제 완료 여부 검증 (미완료 시 인도 불가)
3. **SMS OTP** — 구매 시 등록된 전화번호로 OTP 발송·확인
4. **User Verification**:
   - **CREDITO 구매**: 신용 심사자 사진과 현장 픽업자 사진을 시스템이 비교 → 동일 인물 검증
   - **MSI/CONTADO**: 픽업자 사진과 신분증 사진 요청
5. **Delivery Checklist**:
   - 모터사이클 사진 (전면, 측면 등)
   - 픽업자 사진
   - 신분증 사진
6. **ACTA DE ENTREGA** 서명 — 시스템이 Point Panel에 서명 완료 통보
7. **Deliver motorcycle** — 인도 완료

---

## 5. 알림 규칙 요약

| 시점 | 수신자 | 내용 |
|------|--------|------|
| 포인트 배정 시 | Client | 포인트 주소·연락처 정보 |
| 배송 생성 시 | Client | 배송 포인트, 도착 예정일 (Skydrop API) |
| 배송 생성 시 | Point Panel | 배송 정보 자동 업데이트 |
| 상태가 LISTA PARA ENTREGA로 변경 시 | Client | 픽업 가능 날짜 |
| OTP 인증 시 | Client | 6자리 SMS 코드 |
| ACTA 서명 완료 시 | Point Panel | 서명 완료 통보 |

---

## 6. 현재 구현 상태 vs 다이어그램 차이점

아래 표는 각 기능이 현재 코드베이스에 구현되어 있는지, 부분적인지, 누락되었는지를 정리한다.

| 기능 | 상태 | 위치 / 비고 |
|------|------|-------------|
| **Purchase Panel** (주문 관리) | 구현됨 | [admin/js/modules/admin-ventas.js](admin/js/modules/admin-ventas.js), [admin/php/ventas/](admin/php/ventas/) — 주문 목록, 포인트 배정 대기, 모터사이클 배정 |
| **모터사이클 상태 머신** | 구현됨 | [admin/js/modules/admin-inventario.js](admin/js/modules/admin-inventario.js) — `por_llegar`, `en_ensamble`, `lista_para_entrega`, `entregada` 등 8단계 추적 |
| **Point Panel 재고** | 구현됨 | [puntosvoltika/](puntosvoltika/) — `punto-inventario.js`가 "Para entrega" / "Disponible para venta" 분리 |
| **Point Panel 인도 프로세스** | 구현됨 | `punto-entrega.js` — OTP → 얼굴 인증 → 체크리스트 → 사진 → ACTA 서명 5단계 |
| **Point Panel 수령 (Reception)** | 구현됨 | `punto-recepcion.js` — 배송 수령 처리 |
| **Point Panel 레퍼럴 판매** | 구현됨 | `punto-venta.js` — 쇼룸 재고에서 직접 판매 생성 |
| **Shipping Panel** | 구현됨 | [admin/js/modules/admin-envios.js](admin/js/modules/admin-envios.js) — 주문/재고 대상 배송 생성 |
| **Skydrop API 통합** | 구현됨 | [admin/php/skydropx.php](admin/php/skydropx.php) — 자동 견적·도착 예정일 |
| **CODIGO REFERIDO 생성** | 구현됨 | 포인트별 `código_venta` (오프라인) + `código_electronico` (온라인) 관리 |
| **SMS OTP 인도 인증** | 구현됨 | [admin/php/checklists/enviar-otp.php](admin/php/checklists/enviar-otp.php) — SMSMasivos API, 6자리, 15분 만료 |
| **얼굴 사진 검증** | 구현됨 | [admin/php/checklists/face-compare.php](admin/php/checklists/face-compare.php) — face_score 반환 |
| **인도 체크리스트 + 사진** | 구현됨 | 4개 체크 항목 + 3장 사진(전면·측면·후면) |
| **ACTA DE ENTREGA 서명** | 구현됨 | [clientes/](clientes/) — 디지털 서명, 이름 확인 + 수락 체크 |
| **Client Panel 인도 추적** | 구현됨 | `entrega.js` — 단계별 스테퍼 UI |
| **Configurator 4단계** | 구현됨 | [configurador_prueba/](configurador_prueba/) — 모델·색상·배송지·결제 |
| **CASE 1** (referido 없음) | 구현됨 | DB에 `punto_id='centro-cercano'`로 기록 후 배정 대기 |
| **CASE 2** (Configurator에서 포인트 선택) | **누락** | `paso3-delivery.js`는 우편번호 입력만 있고 **포인트 선택 UI가 없음** — 사용자가 구매 시 포인트를 직접 선택 불가 |
| **CASE 3** (레퍼럴로 일반 판매) | 부분 구현 | Point Panel은 지원하지만 Configurator에서 레퍼럴 코드 → `PUNTO VENTA GENERAL` 자동 연결 로직 불명확 |
| **CASE 4** (레퍼럴로 쇼룸 판매) | 부분 구현 | 쇼룸 재고에서 배정은 가능하나 쇼룸 전용 플로우 구분 없음 |
| **CODIGO REFERIDO 유효성 검증** | **누락** | Configurator에서 입력된 레퍼럴 코드가 유효한지 구매 확정 전에 검증하는 로직 없음 |
| **Point Panel 조립(Assembly) UI** | 부분 구현 | 상태는 추적되나 포인트 스태프가 조립 완료를 표시하는 전용 UI 없음 |
| **LISTA PARA ENTREGA 전환 UI** | 부분 구현 | 상태는 존재하지만 포인트 패널에서 명시적으로 이 상태로 전환하는 인터페이스가 명확하지 않음 |
| **픽업 날짜 지정** | **누락** | 포인트가 `LISTA PARA ENTREGA` 전환 시 픽업 가능 날짜를 입력하여 사용자에게 알리는 기능 없음 |
| **CREDITO 픽업자-신용심사자 얼굴 비교** | 부분 구현 | 얼굴 검증은 있으나 CREDITO 구매 한정 비교 규칙 없음 — 모든 인도 유형에 동일 적용 |
| **Client Panel 포인트 배정 알림** | **누락** | 포인트 배정 시점의 전용 알림/이력 UI 부재 |
| **Client Panel 배송 도착 예정일 표시** | 부분 구현 | Skydrop 견적은 취득하나 고객 포털에 명확히 표시되지 않음 |
| **Configurator 포인트 목록 자동 표시** | **누락** | 우편번호 입력 시 추천 포인트 목록을 보여주고 선택 가능하게 하는 UI 없음 |

---

## 7. 주요 Gap 요약 (우선순위 제안)

### 🔴 Critical — 다이어그램 필수 기능 누락
1. **Configurator 포인트 선택 UI (CASE 2)**  
   우편번호만 수집되고 포인트 선택이 불가능. 다이어그램의 "Assign the point select by the user in the configurator" 플로우가 동작하지 않음.

2. **CODIGO REFERIDO 유효성 검증**  
   잘못된/존재하지 않는 레퍼럴 코드로 구매가 진행될 수 있음. 결제 전 검증 필요.

3. **픽업 날짜 지정 기능**  
   다이어그램의 "needs to put a delivery date to inform the user" 단계가 없음. 포인트가 날짜를 입력 → 사용자 알림 발송 로직 필요.

### 🟡 High — 워크플로우 부분 구현
4. **조립(Assembly) 완료 UI 및 LISTA PARA ENTREGA 전환**  
   포인트 스태프가 조립 완료를 표시하고 상태를 전환하는 전용 인터페이스 필요.

5. **CASE 3/4 구분 처리**  
   일반 판매 vs 쇼룸 판매의 명확한 구분과 각 케이스의 플로우 분기 필요.

6. **CREDITO 구매 전용 얼굴 비교 규칙**  
   신용 심사 시 저장된 사진과 픽업자 사진을 비교하는 로직이 CREDITO 한정으로 분기되어야 함.

### 🟢 Medium — UX 개선
7. **Client Panel 알림 이력/센터**  
   포인트 배정, 배송 시작, 픽업 가능 등의 알림 이력을 고객이 포털에서 확인 가능해야 함.

8. **Configurator 추천 포인트 목록**  
   우편번호 기반 가까운 포인트 목록 표시.

9. **Client Panel Skydrop 도착 예정일 노출**  
   취득한 도착 예정일을 고객 포털에 명확히 표시.
