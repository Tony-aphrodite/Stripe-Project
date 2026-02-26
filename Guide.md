# Análise de Viabilidade Técnica e Proposta de MVP
## Simulador de Acesso Venoso Central com Realidade Aumentada

**Versão:** 1.0
**Data:** 25 de fevereiro de 2026
**Projeto:** Integração de Simulador Físico de Treinamento Médico com Aplicativo de RA

---

## Sumário Executivo

Este documento apresenta uma análise técnica completa para o desenvolvimento de um aplicativo de Realidade Aumentada (RA) integrado a um simulador físico de silicone para treinamento de acesso venoso central guiado por ultrassom.

**Conclusão principal:** O projeto é tecnicamente viável com as tecnologias atuais, porém apresenta um desafio central que define toda a arquitetura — a medição simultânea de estabilidade de ambas as mãos durante o procedimento. Este documento detalha exatamente onde estão as dificuldades, quais soluções existem para cada uma, e propõe um caminho de desenvolvimento em fases que minimiza riscos e maximiza o valor entregue em cada etapa.

---

## Índice

1. [Compreensão do Projeto](#1-compreensão-do-projeto)
2. [Análise Técnica por Funcionalidade](#2-análise-técnica-por-funcionalidade)
3. [O Desafio Mais Difícil do Projeto](#3-o-desafio-mais-difícil-do-projeto)
4. [Stack Tecnológica Recomendada](#4-stack-tecnológica-recomendada)
5. [Arquitetura Técnica Proposta](#5-arquitetura-técnica-proposta)
6. [Fases de Desenvolvimento do MVP](#6-fases-de-desenvolvimento-do-mvp)
7. [Requisitos Mínimos de Dispositivos](#7-requisitos-mínimos-de-dispositivos)
8. [Limitações Conhecidas e Riscos](#8-limitações-conhecidas-e-riscos)
9. [Estimativa de Prazo e Custo](#9-estimativa-de-prazo-e-custo)
10. [Roadmap: Do MVP ao Produto Completo](#10-roadmap-do-mvp-ao-produto-completo)
11. [Conclusão e Próximos Passos](#11-conclusão-e-próximos-passos)

---

## 1. Compreensão do Projeto

### 1.1 O Problema Clínico

O acesso venoso central guiado por ultrassom é um dos procedimentos mais praticados em simulação médica. Durante o procedimento real:

- **Mão esquerda** segura o transdutor de ultrassom contra o pescoço do paciente sobre a veia jugular interna. O transdutor precisa ser mantido estável para gerar uma imagem ultrassonográfica clara.
- **Mão direita** insere a agulha adjacente ao centro do transdutor em um ângulo de aproximadamente 45° (ajustado conforme a profundidade do vaso), avançando sob visualização ultrassonográfica em tempo real.
- **Momento crítico — "troca de mãos":** Após a punção venosa com aspiração de sangue, a mão esquerda precisa passar de segurar o transdutor para estabilizar a agulha, enquanto a mão direita passa da seringa para a passagem do fio-guia (técnica de Seldinger).

**Problema atual:** As taxas de sucesso na primeira tentativa permanecem em torno de 52-62%, em grande parte porque a agulha pode ser deslocada durante a troca de mãos. O feedback do instrutor é visual e subjetivo — não existem métricas quantificáveis de estabilidade durante o treinamento.

### 1.2 A Solução Proposta

Um aplicativo de RA que:
1. Reconhece o modelo físico de silicone via câmera
2. Sobrepõe anatomia virtual (vasos, estruturas) de forma precisa
3. Mede e quantifica a estabilidade das mãos durante o procedimento
4. Fornece feedback objetivo e métricas ao estudante e ao instrutor

### 1.3 Métricas Clinicamente Relevantes

Baseando-se em estudos publicados sobre análise de movimentos em treinamento de acesso venoso central, as métricas mais valiosas são:

| Métrica | Descrição | Relevância Clínica |
|---------|-----------|-------------------|
| **Path length** (comprimento do trajeto) | Distância total percorrida pela mão/agulha | Menor = mais eficiente |
| **Smoothness** (suavidade do movimento) | Número de sub-movimentos, métricas de jerk | Menos sub-movimentos = mais habilidoso |
| **Tremor / steadiness** | Micro-movimentos involuntários durante avanço da agulha | Menor tremor = melhor controle motor |
| **Perfil de velocidade** | Velocidade consistente e controlada na inserção | Velocidade uniforme = mais controle |
| **Estabilidade do transdutor** | Desvio, rotação e variação de pressão do transdutor | Mais estável = melhor imagem ultrassonográfica |
| **Número de redirecionamentos da agulha** | Quantas vezes a agulha muda de trajetória | Menos = melhor coordenação mão-olho |

---

## 2. Análise Técnica por Funcionalidade

### 2.1 Reconhecimento do Modelo Físico

**Requisito:** O aplicativo deve usar a câmera do celular para reconhecer o modelo físico de silicone.

#### Abordagem A: Image Tracking com Marcadores Fiduciais

**Como funciona:** Marcadores visuais (QR codes, padrões impressos) são posicionados estrategicamente no simulador ou na base onde ele está apoiado. A câmera detecta esses marcadores e calcula a posição e orientação 3D do modelo.

| Aspecto | Avaliação |
|---------|-----------|
| **Precisão** | Alta — erro posicional < 5mm em condições controladas |
| **Confiabilidade** | Muito alta — funciona em diferentes condições de iluminação |
| **Compatibilidade** | ARCore (Android) + ARKit (iOS) — suporte completo |
| **Custo de implementação** | Baixo |
| **Limitação** | Exige que os marcadores estejam visíveis e não obstruídos |

**Recomendação:** Esta é a abordagem mais segura e confiável para o MVP. Dois a quatro marcadores posicionados na base do simulador forneceriam tracking robusto e preciso.

#### Abordagem B: Object Tracking sem Marcadores (Model Target)

**Como funciona:** Um modelo CAD 3D do simulador é usado como referência. A câmera reconhece a geometria real e a alinha com o modelo digital.

| Aspecto | Avaliação |
|---------|-----------|
| **Precisão** | Média — depende da complexidade geométrica do objeto |
| **Confiabilidade** | Problemática para silicone — superfície uniforme, sem textura distinta |
| **Compatibilidade** | ARKit apenas (iOS) via AR Foundation; ou Vuforia (ambas plataformas) |
| **Custo de implementação** | Alto — requer licença Vuforia Premium (~$99/mês) e modelo CAD |
| **Limitação crítica** | Objetos de cor uniforme são "difíceis de rastrear de forma robusta" segundo a documentação oficial da Vuforia |

**Avaliação honesta:** Um modelo de silicone, por sua natureza (superfície lisa, cor uniforme, possível translucidez), apresenta as piores condições possíveis para tracking sem marcadores. A Vuforia possui um modo `LOW_FEATURE_OBJECTS` para superfícies simples, mas o desempenho é degradado. Se o modelo tiver detalhes anatômicos salientes (veias visíveis, bordas definidas), a viabilidade melhora.

#### Abordagem Híbrida Recomendada

**Para o MVP:** Image tracking com marcadores fiduciais na base do simulador.
**Para versão futura:** Avaliar object tracking após testes com o modelo físico real, potencialmente combinando marcadores + geometria para maior robustez.

---

### 2.2 Sobreposição de Anatomia Virtual

**Requisito:** Após o reconhecimento, sobrepor vasos e anatomia virtual de forma precisa sobre o simulador.

#### Viabilidade: ALTA

Esta é a funcionalidade mais madura e comprovada do projeto. A sobreposição de modelos 3D sobre objetos rastreados é uma capacidade padrão do AR Foundation.

#### Processo técnico:

1. **Modelagem 3D anatômica:** Criar modelo 3D dos vasos (jugular interna, carótida), músculos (esternocleidomastoideo), e referências ósseas usando software como Blender ou Maya, com referência anatômica precisa.

2. **Calibração de registro:** Alinhar o modelo 3D virtual com o modelo físico. Com marcadores fiduciais, a calibração é feita uma vez: define-se a posição exata de cada estrutura anatômica em relação aos marcadores.

3. **Renderização em tempo real:** Unity renderiza o modelo 3D em tempo real, ajustando posição e orientação conforme o tracking da câmera.

#### Precisão esperada:

| Cenário | Precisão Posicional | Adequação Clínica |
|---------|---------------------|-------------------|
| Image tracking (marcadores) — condições boas | 1-5 mm | Adequada para treinamento |
| Image tracking — condições médias | 5-10 mm | Aceitável para orientação |
| Object tracking (sem marcadores) | 10-30 mm | Insuficiente para treinamento preciso |

**Nota importante:** Para treinamento de acesso venoso central, a precisão milimétrica é clinicamente relevante. A jugular interna tem diâmetro médio de 11-16mm, e a carótida está a apenas 5-10mm lateral. Uma sobreposição com erro > 10mm poderia ensinar posicionamento incorreto. Com marcadores fiduciais e calibração cuidadosa, a precisão de 1-5mm é alcançável e clinicamente adequada.

#### Nível de detalhe recomendado para MVP:

- Veia jugular interna (alvo da punção)
- Artéria carótida (estrutura a evitar)
- Músculo esternocleidomastoideo (referência anatômica principal)
- Referências ósseas básicas (clavícula, manúbrio esternal)
- Indicação visual do ângulo e profundidade ideais de punção

---

### 2.3 Feedback de Estabilidade — Mão Esquerda (Transdutor)

**Requisito:** Medir a estabilidade da mão que segura o "transdutor" (celular).

#### Viabilidade: ALTA

Se o celular simula o transdutor de ultrassom — o estudante segura o celular com a mão esquerda e o posiciona sobre o modelo — então os sensores internos do celular medem diretamente a estabilidade dessa mão.

#### Sensores disponíveis e o que medem:

| Sensor | Medição | Aplicação |
|--------|---------|-----------|
| **Giroscópio** | Velocidade angular (rotação) | Detecta tremor rotacional e desvios de ângulo |
| **Acelerômetro** | Aceleração linear | Detecta micro-movimentos, tremor translacional |
| **Magnetômetro** | Orientação magnética | Complementa orientação absoluta |
| **Fusão IMU** (giroscópio + acelerômetro + magnetômetro) | Orientação 3D completa | Perfil de estabilidade completo |

#### Métricas que podem ser extraídas diretamente:

1. **Tremor do transdutor:** Magnitude e frequência de micro-movimentos involuntários (Hz e amplitude em graus)
2. **Desvio angular:** Quanto a orientação do "transdutor" desvia da posição ideal ao longo do tempo
3. **Suavidade de posicionamento:** Jerk (derivada da aceleração) — quanto mais suave, mais controlado
4. **Tempo de estabilização:** Quanto tempo o estudante leva para manter o transdutor estável após posicionamento
5. **Mapa de calor de estabilidade:** Visualização temporal das regiões de maior e menor estabilidade

**Implementação:** Módulo de processamento de sinais que filtra ruído dos sensores (filtro complementar ou filtro de Kalman), calcula métricas em janelas temporais, e armazena os dados para análise posterior e feedback em tempo real.

**Grau de confiança técnica: 95%.** Esta é a funcionalidade com maior certeza de sucesso.

---

### 2.4 Feedback de Estabilidade — Mão Direita (Punção)

**Requisito:** Medir a estabilidade e o movimento da mão que realiza a punção com agulha.

#### Viabilidade: MODERADA — Este é o maior desafio técnico do projeto.

A mão direita não está segurando o celular. Portanto, os sensores do celular não podem medi-la diretamente. A única opção é **rastrear a mão direita visualmente através da câmera**.

#### Cenário de uso analisado:

```
                    ┌─────────────┐
                    │   Celular   │  ← Segurado pela mão esquerda
                    │  (câmera ↓) │     como "transdutor"
                    └──────┬──────┘
                           │
                           ▼  Campo de visão da câmera
                    ┌─────────────┐
                    │  Modelo de  │  ← Superfície do simulador
                    │  Silicone   │
                    └─────────────┘
                           ↑
                    ┌──────┴──────┐
                    │ Mão direita │  ← Com agulha, entrando pelo
                    │  + agulha   │     lado do campo de visão
                    └─────────────┘
```

**Problema fundamental:** A câmera do celular está apontada para baixo, na direção do modelo. A mão direita pode ou não estar dentro do campo de visão, dependendo da posição.

#### Opções técnicas avaliadas:

**Opção A: MediaPipe Hands via câmera do celular**

| Aspecto | Avaliação |
|---------|-----------|
| Plataforma | Android + iOS |
| Precisão | ~3.1mm de erro médio (validado em estudo de 2024 para treinamento médico) |
| Performance | 15-30ms por frame em dispositivos 2022+ |
| Limitação 1 | Precisa rodar simultaneamente com AR tracking — viável em dispositivos mid-range 2022+ |
| Limitação 2 | A mão precisa estar no campo de visão da câmera |
| Limitação 3 | Detecção de 21 landmarks da mão, mas não rastreia a agulha especificamente |

**Opção B: Apple Vision Framework (ARKit — apenas iOS)**

| Aspecto | Avaliação |
|---------|-----------|
| Plataforma | Apenas iOS 14+ |
| Precisão | 2D apenas (x,y na imagem); 3D com LiDAR em iPhone Pro |
| Performance | Nativo, integrado ao sistema — menor overhead que MediaPipe |
| Limitação 1 | Sem profundidade em iPhones sem LiDAR |
| Limitação 2 | Mesma limitação de campo de visão |

**Opção C: Abordagem com segundo dispositivo**

| Aspecto | Avaliação |
|---------|-----------|
| Conceito | Um segundo celular/tablet posicionado para observar ambas as mãos |
| Precisão | Alta — campo de visão amplo, mãos sempre visíveis |
| Limitação | Complexidade logística, necessidade de sincronização entre dispositivos |

#### Solução recomendada para o MVP:

**MediaPipe Hands** como camada de processamento sobre os frames da câmera do AR, com as seguintes adaptações:

1. **Posicionamento orientado:** Guiar o estudante a posicionar o celular de forma que a mão direita fique visível no campo de visão
2. **Métricas adaptadas:** Quando a mão não estiver visível, o sistema informa ao estudante (não calcula métricas incorretas)
3. **Indicador de confiança:** Cada métrica acompanhada de um nível de confiança baseado na qualidade do tracking

**Grau de confiança técnica: 60%.** Funcionalidade viável mas com limitações reais de campo de visão. A experiência do usuário precisará ser cuidadosamente projetada para mitigar essas limitações.

---

## 3. O Desafio Mais Difícil do Projeto

### 3.1 Definição do Problema

O desafio central não é nenhuma funcionalidade isolada — é a **operação simultânea** de múltiplos pipelines computacionais em um dispositivo mobile:

```
┌──────────────────────────────────────────────────┐
│                 UM ÚNICO CELULAR                  │
│                                                   │
│  Pipeline 1: AR Tracking                         │
│  ├─ Detectar marcadores fiduciais                │
│  ├─ Calcular posição 3D do modelo                │
│  └─ Atualizar posição da anatomia virtual        │
│                                                   │
│  Pipeline 2: Renderização 3D                     │
│  ├─ Renderizar modelo anatômico                  │
│  └─ Renderizar UI de feedback                    │
│                                                   │
│  Pipeline 3: Sensores IMU                        │
│  ├─ Capturar dados giroscópio + acelerômetro     │
│  ├─ Filtrar ruído (Kalman)                       │
│  └─ Calcular métricas de estabilidade            │
│                                                   │
│  Pipeline 4: Hand Tracking (MediaPipe)           │
│  ├─ Processar frame da câmera                    │
│  ├─ Detectar e rastrear mão direita              │
│  └─ Calcular métricas de punção                  │
│                                                   │
│  TUDO SIMULTÂNEO. TUDO EM TEMPO REAL. 30 FPS.   │
└──────────────────────────────────────────────────┘
```

### 3.2 Por Que Isso é Difícil

1. **AR Tracking e MediaPipe competem pela câmera.** Ambos precisam processar frames da câmera, mas são pipelines separados. O ARCore/ARKit não compartilha frames nativamente com o MediaPipe — é necessário uma implementação customizada que intercepte os frames e alimente ambos os pipelines.

2. **Carga computacional combinada.** Cada pipeline individualmente funciona bem em dispositivos modernos. Todos juntos em 30 fps é o que exige dispositivos potentes e otimização cuidadosa.

3. **Conflito de campo de visão.** A câmera não pode apontar para o modelo (para tracking) e para a mão (para hand tracking) simultaneamente se eles não estiverem no mesmo enquadramento.

### 3.3 Soluções Propostas

#### Solução 1: Arquitetura de Pipeline Compartilhado

```
Camera Frame (30 fps)
        │
        ├──→ ARCore/ARKit (tracking do modelo)
        │         └──→ Posição 3D → Renderização
        │
        └──→ MediaPipe Hands (a cada 2-3 frames)
                  └──→ Landmarks da mão → Métricas
```

**Executar o MediaPipe em frames alternados** (15 fps em vez de 30 fps) reduz a carga computacional pela metade enquanto mantém tracking de mão suficientemente responsivo para medir estabilidade. A estabilidade é uma métrica de baixa frequência — tremor humano opera entre 4-12 Hz, que é adequadamente capturado a 15 fps.

#### Solução 2: Separação em Fases do Procedimento

Em vez de rodar tudo simultaneamente durante todo o procedimento, separar em fases:

| Fase do Procedimento | Pipelines Ativos | Carga |
|----------------------|-----------------|-------|
| **Posicionamento** — estudante posiciona o celular | AR Tracking + Renderização 3D | Leve |
| **Avaliação do transdutor** — estabilidade da mão esquerda | AR Tracking + IMU + Renderização | Média |
| **Avaliação da punção** — estabilidade da mão direita | AR Tracking + Hand Tracking + Renderização | Alta |
| **Revisão** — análise pós-procedimento | Nenhum em tempo real — replay dos dados | Mínima |

#### Solução 3: MVP sem Hand Tracking da Mão Direita

A abordagem mais pragmática para o MVP:

- **Mão esquerda (transdutor):** Feedback completo via sensores IMU — alto valor pedagógico, alta confiabilidade
- **Mão direita (punção):** No MVP, usar apenas o ângulo de inserção detectável quando a mão/agulha passa pelo campo de visão, sem tracking contínuo
- **Hand tracking completo da mão direita:** Reservado para a versão Quest 3, onde o headset tem câmeras dedicadas e hand tracking nativo de alta qualidade

**Esta é a recomendação mais honesta.** O feedback da mão esquerda sozinho já entrega valor pedagógico enorme — nenhum simulador atual oferece métricas objetivas de estabilidade do transdutor.

---

## 4. Stack Tecnológica Recomendada

### 4.1 Recomendação Principal

| Componente | Tecnologia | Justificativa |
|------------|-----------|---------------|
| **Engine** | Unity 6 (LTS) | Maior ecossistema para AR/XR, suporte direto a Quest 3, comunidade extensa |
| **Framework AR** | AR Foundation 6.x | Abstração multi-plataforma (ARCore + ARKit), caminho natural para Quest 3 |
| **Tracking** | AR Foundation Image Tracking | Mais confiável para o modelo de silicone usando marcadores fiduciais |
| **Hand Tracking** | MediaPipe Hands (Android) + Vision Framework (iOS) | Melhor combinação de performance e compatibilidade |
| **Modelagem 3D** | Blender (gratuito) | Exportação para Unity (.fbx/.gltf), adequado para modelagem anatômica |
| **Processamento de Sinais** | Módulo C# customizado em Unity | Filtro de Kalman para dados IMU, cálculo de métricas em tempo real |
| **Backend (opcional)** | Firebase | Armazenamento de sessões de treinamento, analytics, autenticação |

### 4.2 Alternativas Avaliadas e Descartadas

| Alternativa | Por Que Foi Descartada |
|-------------|----------------------|
| **8th Wall (WebAR)** | Performance insuficiente para 4 pipelines simultâneos; sem acesso direto a sensores IMU com a precisão necessária; não permite migração para Quest 3 |
| **Vuforia Model Targets** | Custo adicional de licenciamento (~$99/mês); silicone de cor uniforme tem tracking "difícil e não robusto" segundo documentação oficial; marcadores fiduciais com AR Foundation são mais confiáveis e gratuitos |
| **Unreal Engine** | Curva de aprendizado mais íngreme para AR mobile; comunidade menor para AR Foundation; overhead de performance desnecessário para este tipo de aplicação |
| **RealityKit (Apple)** | Apenas iOS — elimina metade do mercado; não tem caminho para Quest 3 |

---

## 5. Arquitetura Técnica Proposta

### 5.1 Arquitetura de Alto Nível

```
┌─────────────────────────────────────────────────────────────────┐
│                        APLICATIVO UNITY                         │
│                                                                 │
│  ┌──────────────┐  ┌──────────────┐  ┌────────────────────┐   │
│  │   Módulo AR   │  │  Módulo de   │  │  Módulo de Hand    │   │
│  │   Tracking    │  │  Renderização│  │  Tracking          │   │
│  │              │  │  3D          │  │  (MediaPipe/Vision) │   │
│  │  - Image     │  │              │  │                    │   │
│  │    Tracking  │  │  - Anatomia  │  │  - Detecção mão   │   │
│  │  - Pose      │  │    virtual   │  │  - 21 landmarks   │   │
│  │    Estimation│  │  - UI overlay│  │  - Métricas        │   │
│  └──────┬───────┘  └──────┬───────┘  └────────┬───────────┘   │
│         │                 │                    │               │
│  ┌──────┴─────────────────┴────────────────────┴───────────┐   │
│  │              MÓDULO DE PROCESSAMENTO CENTRAL             │   │
│  │                                                         │   │
│  │  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐  │   │
│  │  │ Processador  │  │  Calculador  │  │  Sistema de  │  │   │
│  │  │ de Sinais IMU│  │  de Métricas │  │  Feedback    │  │   │
│  │  │ (Kalman)     │  │  Clínicas    │  │  em Tempo    │  │   │
│  │  │              │  │              │  │  Real        │  │   │
│  │  └──────────────┘  └──────────────┘  └──────────────┘  │   │
│  └─────────────────────────────────────────────────────────┘   │
│                                                                 │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │              MÓDULO DE DADOS E ANALYTICS                 │   │
│  │                                                         │   │
│  │  - Gravação de sessões    - Histórico de desempenho     │   │
│  │  - Exportação de métricas - Comparação entre sessões    │   │
│  └─────────────────────────────────────────────────────────┘   │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

### 5.2 Fluxo de Dados em Tempo Real

```
Câmera (30 fps)                          Sensores IMU (100 Hz)
      │                                         │
      ▼                                         ▼
┌─────────────┐                        ┌──────────────┐
│ AR Foundation│                        │ Filtro de    │
│ Image Track  │                        │ Kalman       │
└──────┬──────┘                        └──────┬───────┘
       │                                      │
       ▼                                      ▼
┌─────────────┐                        ┌──────────────┐
│ Posição 3D  │                        │ Orientação   │
│ do modelo   │                        │ 3D filtrada  │
└──────┬──────┘                        └──────┬───────┘
       │                                      │
       ▼                                      ▼
┌─────────────────────────────────────────────────┐
│           CALCULADOR DE MÉTRICAS                 │
│                                                  │
│  Entrada:  Posição modelo + Orientação celular   │
│  Saída:    Métricas de estabilidade em tempo real │
│                                                  │
│  - Tremor magnitude (°/s)                        │
│  - Desvio angular acumulado (°)                  │
│  - Suavidade (jerk normalizado)                  │
│  - Score de estabilidade (0-100)                 │
└──────────────────────┬──────────────────────────┘
                       │
                       ▼
              ┌────────────────┐
              │ FEEDBACK VISUAL │
              │ (UI em tempo   │
              │  real no app)  │
              └────────────────┘
```

---

## 6. Fases de Desenvolvimento do MVP

### Fase 1: Fundação — Tracking + Overlay + Sensores IMU (Semanas 1-3)

**Objetivo:** App funcional que reconhece o modelo, sobrepõe anatomia virtual e captura dados de estabilidade do transdutor.

**Entregas:**
- Projeto Unity configurado com AR Foundation (ARCore + ARKit)
- Sistema de image tracking com marcadores fiduciais
- Modelo 3D anatômico básico (jugular interna, carótida, esternocleidomastoideo)
- Sobreposição estável do modelo 3D sobre o simulador
- Módulo de captura e filtragem de dados IMU (giroscópio + acelerômetro)
- Implementação de filtro de Kalman para fusão de sensores
- UI básica (iniciar sessão, ajuda de posicionamento)

**Critério de sucesso:** O estudante aponta o celular para o simulador, vê a anatomia virtual sobreposta de forma estável e precisa, e o sistema já captura dados de estabilidade da mão esquerda em ambas as plataformas (iOS + Android).

**Risco técnico:** Baixo. Tracking e sensores IMU são tecnologias maduras que podem ser desenvolvidas em paralelo.

### Fase 2: Feedback em Tempo Real + Hand Tracking (Semanas 4-6)

**Objetivo:** Feedback visual de estabilidade da mão esquerda em tempo real e detecção básica da mão direita.

**Entregas:**
- Cálculo de métricas em tempo real: tremor, desvio angular, suavidade, score de estabilidade (0-100)
- Feedback visual na tela: indicador verde (estável) / amarelo (aceitável) / vermelho (instável)
- Integração do MediaPipe Hands (Android) e Vision Framework (iOS)
- Detecção da mão direita quando visível no campo de visão da câmera
- Indicador visual de "mão detectada" / "mão fora do campo"
- Métricas básicas da mão direita: ângulo de inserção, estabilidade quando visível

**Critério de sucesso:** O estudante recebe feedback visual imediato sobre a estabilidade do transdutor, e o app detecta a presença da mão de punção quando está no campo de visão.

**Risco técnico:** Moderado. O feedback IMU é confiável; o hand tracking da mão direita depende do enquadramento.

### Fase 3: Sistema de Revisão + Polimento (Semanas 7-8)

**Objetivo:** Sistema de gravação/revisão de sessões e app pronto para testes reais.

**Entregas:**
- Gravação completa de cada sessão de treinamento (métricas + timestamps)
- Tela de revisão pós-sessão com gráficos e métricas
- Comparação entre sessões (curva de aprendizagem)
- Exportação de dados em formato CSV para uso acadêmico
- Otimização de performance em dispositivos-alvo
- Correção de bugs e ajustes de UX
- Builds de teste para iOS (TestFlight) e Android (teste interno)

**Critério de sucesso:** App estável com ciclo completo: treinar → receber feedback → revisar métricas → comparar evolução. Pronto para testes com profissionais médicos.

**Risco técnico:** Baixo. Funcionalidades de software padrão com prazo adequado.

---

## 7. Requisitos Mínimos de Dispositivos

### Android

| Especificação | Mínimo | Recomendado |
|---------------|--------|-------------|
| **Processador** | Snapdragon 778G ou equivalente (2022+) | Snapdragon 8 Gen 1 ou superior |
| **RAM** | 6 GB | 8 GB+ |
| **Android** | 12.0+ | 13.0+ |
| **Câmera** | Compatível com ARCore | — |
| **Exemplos** | Samsung Galaxy A54, Pixel 6 | Samsung Galaxy S22+, Pixel 7 Pro |

### iOS

| Especificação | Mínimo | Recomendado |
|---------------|--------|-------------|
| **Processador** | A12 Bionic (2018+) | A15 Bionic ou superior |
| **iOS** | 16.0+ | 17.0+ |
| **Modelo** | iPhone XR / XS | iPhone 13 Pro+ (com LiDAR) |

### Meta Quest 3 (Fase Futura)

| Especificação | Requisito |
|---------------|-----------|
| **Dispositivo** | Meta Quest 3 |
| **Software** | Quest OS v76+ (Passthrough Camera API) |
| **Hand Tracking** | Nativo — 26 joints por mão, ~70ms latência |

---

## 8. Limitações Conhecidas e Riscos

### 8.1 Matriz de Riscos

| Risco | Probabilidade | Impacto | Mitigação |
|-------|--------------|---------|-----------|
| **Tracking instável em iluminação variável** | Média | Médio | Marcadores fiduciais de alto contraste; guia de condições ideais no app |
| **Mão direita fora do campo de visão** | Alta | Alto | Guia de posicionamento; aceitar limitação no MVP; resolver com Quest 3 |
| **Performance insuficiente em dispositivos mínimos** | Média | Alto | Pipeline alternado para hand tracking; profiling contínuo durante desenvolvimento |
| **Precisão de overlay insuficiente para ensino** | Baixa | Muito Alto | Calibração cuidadosa com marcadores; validação com profissional de anatomia |
| **Modelo de silicone difícil de rastrear sem marcadores** | Alta (se tentar) | Alto | MVP usa marcadores — este risco é eliminado pela decisão de design |
| **MediaPipe + AR Foundation conflitam na câmera** | Média | Alto | Implementação de pipeline compartilhado testada em protótipo antes do MVP |

### 8.2 Limitações que o Usuário Final Deve Conhecer

1. **Iluminação:** O app funcionará melhor em ambientes com iluminação uniforme e adequada. Iluminação muito fraca ou sombras fortes degradam o tracking.

2. **Campo de visão:** O celular tem campo de visão limitado (~60-70°). Nem sempre será possível ver o modelo inteiro e ambas as mãos simultaneamente.

3. **Feedback da mão direita:** No MVP, o feedback da mão de punção será menos confiável que o da mão do transdutor. A versão Quest 3 resolverá essa limitação.

4. **Precisão não substitui supervisão clínica.** O app é uma ferramenta de treinamento complementar, não um substituto para o ensino supervisionado.

---

## 9. Estimativa de Prazo

| Fase | Escopo | Duração | Acumulado |
|------|--------|---------|-----------|
| Fase 1 | Tracking + Overlay + Sensores IMU | 3 semanas | 3 semanas |
| Fase 2 | Feedback em Tempo Real + Hand Tracking | 3 semanas | 6 semanas |
| Fase 3 | Sistema de Revisão + Polimento | 2 semanas | 8 semanas |

**Prazo total estimado para o MVP: 8 semanas (~2 meses)**

A compressão do prazo é viável porque:
- Fase 1 combina tracking e sensores IMU em desenvolvimento paralelo (são módulos independentes)
- Fase 2 combina feedback visual e hand tracking, aproveitando a base já construída
- Fase 3 integra revisão de sessões e polimento, focando na experiência final do usuário

---

## 10. Roadmap: Do MVP ao Produto Completo

```
2026                                    2027
──────────────────────────────────────────────────────────────

Q1-Q2 2026: MVP MOBILE
├─ App iOS + Android
├─ Tracking com marcadores
├─ Overlay anatômico
├─ Feedback estabilidade (mão esquerda)
└─ Detecção básica mão direita

Q3 2026: VERSÃO 1.1 - APRIMORAMENTOS
├─ Object tracking sem marcadores (se viável com modelo real)
├─ Melhoria nas métricas de mão direita
├─ Modo instrutor avançado
└─ Integração com plataforma de ensino (LMS)

Q4 2026: VERSÃO QUEST 3
├─ Migração para Meta Quest 3
├─ Hand tracking nativo de ambas as mãos (sem limitação de câmera)
├─ Experiência imersiva completa
├─ Feedback háptico (vibração dos controllers)
└─ Modo multiplayer (instrutor + estudante)

Q1 2027: VERSÃO 2.0 - PLATAFORMA
├─ Múltiplos procedimentos (além de acesso venoso central)
├─ Sistema de scoring e certificação
├─ Dashboard para instituições de ensino
└─ API para integração com outros simuladores
```

### Compatibilidade Arquitetural: Mobile → Quest 3

A escolha de Unity + AR Foundation garante que a migração para Quest 3 seja significativamente facilitada:

| Componente | Reutilização Estimada |
|-----------|----------------------|
| Lógica de métricas e processamento IMU | 90% |
| Modelos 3D anatômicos | 100% |
| Sistema de gravação e revisão de sessões | 85% |
| UI e interação | 30% (paradigma diferente: toque → mãos/gestos) |
| AR Tracking | 50% (AR Foundation abstrai, mas Quest 3 tem diferenças) |
| Hand Tracking | 20% (Quest 3 usa sistema nativo diferente, mas muito superior) |

**Estimativa de reutilização total: 55-65% do código base.** O investimento no MVP mobile não é descartado — é fundação.

---

## 11. Conclusão e Próximos Passos

### O projeto é viável?

**Sim, com ressalvas honestas:**

| Funcionalidade | Viabilidade | Confiança |
|---------------|------------|-----------|
| Reconhecimento do modelo (com marcadores) | Alta | 95% |
| Sobreposição de anatomia virtual | Alta | 95% |
| Feedback estabilidade mão esquerda (transdutor) | Alta | 95% |
| Feedback estabilidade mão direita (punção) | Moderada | 60% |
| Migração futura para Quest 3 | Alta | 85% |

### O que torna este projeto especial

O mercado de simulação médica cresce 15-17% ao ano. Simuladores físicos com feedback objetivo e quantificável são raros. A combinação de um modelo tátil de silicone com um aplicativo de RA que fornece métricas reais de desempenho é um diferencial significativo — mesmo que o MVP ofereça apenas o feedback da mão esquerda (transdutor), já seria único no mercado.

### Próximos Passos Recomendados

1. **Imediato:** Enviar fotos e especificações do modelo de silicone para avaliação de trackability
2. **Semana 1:** Definir como o celular se posiciona durante o procedimento simulado
3. **Semana 2:** Definir o nível de detalhe anatômico desejado na sobreposição
4. **Semana 3-4:** Protótipo mínimo de tracking com marcadores no modelo real
5. **Semana 4:** Decisão sobre escopo do MVP (completo ou reduzido)

---

*Este documento foi elaborado com base em pesquisa técnica atualizada de ferramentas e frameworks de RA, estudos publicados sobre análise de movimentos em treinamento de acesso venoso central, e experiência prática em desenvolvimento de aplicações de realidade aumentada.*
