# Painel-Gestao-Automatizacao-Afiliado

Caçador de ofertas de **ferramentas** no Mercado Livre com publicação automática em
**grupo de WhatsApp** — mecânica, marcenaria, construção, jardinagem, elétrica,
pintura, solda e medição. Busca pelo navegador, calcula desconto real e comissão de
afiliado, ranqueia as ofertas e publica de tempos em tempos — tudo configurável.

O núcleo é PHP 8.1+ **sem Composer e sem nenhuma biblioteca externa** — só precisa de
PHP com `curl`, `pdo_sqlite`, `dom` e `mbstring`, e do Chrome (ou Edge) instalado.

A única peça em Node.js é a ponte com o WhatsApp, que existe porque não há como falar
com o WhatsApp Web em PHP puro. Ela se instala sozinha no primeiro `conectar` e só
precisa de Node 20+.

---

## Como funciona

```
config/buscas.php
       │
       ▼
  Coleta ──────────► Chrome headless (DevTools) lê a página renderizada do ML
       │             (com fallback para a API pública quando ela está liberada)
       ▼
  Análise ─────────► relevância do nicho · comissão · histórico · desconto falso
       │
       ▼
  Filtros ─────────► desconto mín, comissão mín, nota, palavras bloqueadas,
       │             não repetir oferta já enviada
       ▼
  Pontuação ───────► nota 0–100 define a ordem de publicação
       │
       ▼
  Publicação ──────► WhatsApp via ponte local (QR code) ou gateway externo
```

Tudo fica registrado num SQLite em `storage/mlgroup.sqlite`: produtos, histórico de
preços, envios e execuções.

---

## Duas limitações que você precisa saber antes

**1. A API oficial da Meta não envia para grupos.** Não é limitação deste projeto —
a Cloud API do WhatsApp Business simplesmente não expõe grupos. Todo envio para grupo
passa, obrigatoriamente, por uma conexão com o WhatsApp Web.

O padrão do projeto é a **ponte local** (`ponte/servidor.js`): você roda um comando,
escaneia o QR code e pronto. A sessão fica salva, então o QR só é pedido de novo se
você desconectar o aparelho pelo celular. Não precisa de Docker, servidor nem conta em
serviço pago — só Node.js 20+ (o instalador do projeto cuida das dependências).

| Driver | O que exige | Quando usar |
|---|---|---|
| `ponte` **(padrão)** | Node 20+ | quer só ler o QR e usar |
| `evolution` | servidor próprio | já tem [Evolution API](https://github.com/EvolutionAPI/evolution-api) rodando |
| `zapi` | conta paga | não quer manter nada |
| `wppconnect` | servidor próprio | já tem [WPPConnect](https://github.com/wppconnect-team/wppconnect-server) |
| `simulado` | nada | testar sem enviar |

Automação não-oficial no WhatsApp pode levar ao bloqueio do número. Use um chip
dedicado, nunca o pessoal, e respeite o intervalo entre mensagens.

**2. A comissão do afiliado não vem por API.** O Mercado Livre mostra o percentual só
na Central de Afiliados, e ele muda por categoria e campanha. A tabela fica em
[`config/comissoes.php`](config/comissoes.php) e **você precisa conferir e ajustar** —
se ela estiver errada, o filtro de comissão e a pontuação erram junto.

---

## Instalação

```bash
cd d:\Painel-Gestao-Automatizacao-Afiliado
php tests/smoke.php        # autoteste, não toca no ML nem no WhatsApp
php bin/mlgroup conectar   # instala a ponte, mostra o QR e escolhe o grupo
```

O `conectar` faz tudo:

1. instala as dependências da ponte na primeira vez (`npm install` automático);
2. sobe a ponte e desenha o **QR code no terminal**;
3. você abre **WhatsApp > Aparelhos conectados > Conectar um aparelho** e aponta a
   câmera;
4. ao conectar, ele **lista seus grupos numerados** para você escolher;
5. grava o grupo escolhido no `.env` sozinho.

Depois disso é só `php bin/mlgroup ciclo`.

Para trocar de grupo mais tarde: `php bin/mlgroup grupos`.
Para sair da conta: `php bin/mlgroup desconectar`.

### Certificados HTTPS (Windows)

O PHP para Windows não traz certificados raiz e toda chamada HTTPS falha com
*"unable to get local issuer certificate"*. O sistema procura sozinho um bundle já
instalado (Git for Windows, XAMPP, Laragon). Se não achar nenhum, baixe
[cacert.pem](https://curl.se/ca/cacert.pem), salve em `storage/cacert.pem` — ou aponte
o caminho em `config/config.php` → `http.ca_bundle`.

---

## Configuração

| Arquivo | O que fica lá |
|---|---|
| [`.env`](.env.example) | credenciais: gateway de WhatsApp, IDs de grupo, tag de afiliado |
| [`config/config.php`](config/config.php) | regras: filtros, pesos da pontuação, agenda, envio |
| [`config/buscas.php`](config/buscas.php) | o que procurar |
| [`config/comissoes.php`](config/comissoes.php) | comissão por categoria e por palavra |
| [`config/nicho.php`](config/nicho.php) | o que é (e o que não é) item de oficina |
| [`templates/*.txt`](templates/) | texto das mensagens |

### Escolher o grupo

```bash
php bin/mlgroup grupos
```

Mostra a lista numerada, você digita o número (ou vários separados por vírgula) e ele
grava em `WHATSAPP_GRUPOS` no `.env`. O `*` marca o que já está configurado.

Se o grupo for **restrito a administradores**, o comando avisa — nesse caso o número
conectado precisa ser admin do grupo para conseguir publicar.

### Tipos de busca

```php
// 1. Ofertas do dia por categoria — o caminho mais confiável
['nome' => 'Ofertas', 'tipo' => 'ofertas', 'categoria' => 'MLB263532'],

// 2. Termo com filtros — o sistema monta a URL de lista
['tipo' => 'termo', 'termo' => 'esmerilhadeira', 'desconto_min' => 20,
 'preco_min' => 100, 'preco_max' => 1200, 'apenas_frete_gratis' => true],

// 3. URL colada do navegador — filtre no site e cole aqui
['tipo' => 'url', 'url' => 'https://lista.mercadolivre.com.br/...'],
```

Para achar IDs de categoria: `php bin/mlgroup categorias ferramentas`.

---

## Painel

```bash
php bin/mlgroup painel        # abre http://127.0.0.1:8321 no navegador
```

Dashboard local com barra lateral e cinco telas:

| Tela | O que mostra |
|---|---|
| **Visão geral** | indicadores do dia, envios por dia (14 dias), funil coleta → aprovação → publicação, últimas execuções e envios |
| **Configuração** | filtros, agenda, envio, coleta, nicho, pesos da pontuação, afiliado, WhatsApp e log |
| **Buscas** | editar o que o sistema procura |
| **Fila** | o que está aprovado esperando a vez |
| **Mensagem** | prévia de como a oferta chega no grupo |

Os gráficos são SVG gerado no servidor — sem biblioteca, sem CDN, funciona offline.
As cores saem de uma paleta **validada para daltonismo e contraste**, não escolhidas no
olho: azul único para série de tempo, rampa ordinal de um só tom para o funil, e cores
de status reservadas que nunca viram "série 4". Texto nunca usa a cor da série — a
identidade fica na marca, a leitura fica na tinta. Tema claro e escuro acompanham o
sistema operacional.

Responde apenas em `127.0.0.1`: é ferramenta de configuração local, não um site.

**O painel não reescreve os arquivos de `config/`.** Eles são código comentado, que
explica cada regra, e um gerador de PHP acabaria com isso. O que você muda vai para
`storage/config-local.json`, uma camada por cima — os arquivos seguem sendo o padrão e
o JSON, a exceção. O botão **Restaurar padrões** apaga essa camada.

O laço do `rodar` relê a configuração a cada ciclo, então o que você salvar no painel
vale no próximo ciclo, sem reiniciar nada.

---

## Uso

```bash
php bin/mlgroup painel            # interface web de configuração
php bin/mlgroup conectar          # QR code + escolha do grupo
php bin/mlgroup grupos            # trocar de grupo
php bin/mlgroup desconectar       # sair da conta do WhatsApp

php bin/mlgroup analisar          # ranking das ofertas, sem enviar nada
php bin/mlgroup previa            # renderiza a mensagem, para conferir o template
php bin/mlgroup ciclo             # uma rodada completa: caça e publica
php bin/mlgroup ciclo --analise   # uma rodada sem publicar
php bin/mlgroup rodar             # laço contínuo no intervalo configurado
php bin/mlgroup rodar --intervalo=90
php bin/mlgroup whatsapp          # testa a conexão do gateway
php bin/mlgroup relatorio --dias=30
php bin/mlgroup limpar --dias=60  # remove dados antigos
```

`--verbose` em qualquer comando mostra o motivo de cada reprovação — é assim que se
calibra os filtros.

### Ordem sugerida para começar

1. `php bin/mlgroup conectar` — QR code e escolha do grupo
2. `php bin/mlgroup analisar --verbose` — veja o que passa e o que é reprovado
3. Ajuste `config/config.php` → `filtros` até o ranking ficar bom
4. `php bin/mlgroup previa` — confira o texto da mensagem
5. `php bin/mlgroup ciclo --analise` — ensaio completo, sem enviar
6. `php bin/mlgroup ciclo` — publica de verdade

---

## Agendamento

**Laço próprio** (o processo fica aberto):

```bash
php bin/mlgroup rodar --intervalo=60
```

Respeita a janela de `config/config.php` → `agenda`, para o bot não acordar o grupo de
madrugada. Configuração atual: **publica das 09:00 às 22:00, todos os dias**. O fim é
exclusivo — às 21:59 ainda envia, às 22:00 não envia mais.

Fora da janela o ciclo continua acordando e apenas registra que está fora do horário;
nada é publicado e nada se perde — a fila espera o dia seguinte.

**Agendador de Tarefas do Windows** (recomendado em produção — sobrevive a reboot):

```
Programa:    php
Argumentos:  D:\Painel-Gestao-Automatizacao-Afiliado\bin\mlgroup ciclo
Iniciar em:  D:\Painel-Gestao-Automatizacao-Afiliado
Gatilho:     repetir a cada 1 hora
```

**cron** (Linux):

```
0 * * * * cd /caminho/Painel-Gestao-Automatizacao-Afiliado && php bin/mlgroup ciclo >> storage/logs/cron.log 2>&1
```

---

## Sobre a coleta

O Mercado Livre trata acesso automatizado de forma diferente conforme a página:

- **`/ofertas`** — passa sem problema, é a fonte mais estável.
- **`lista.mercadolivre.com.br`** (buscas por termo) — protegida. Cai numa página de
  verificação de tráfego se o acesso for frequente demais.

O que o sistema já faz por conta disso:

- **aquece a sessão** visitando a home antes da primeira lista (sem isso a lista é
  barrada quase sempre);
- **disfarça o headless** — corrige `navigator.webdriver` e usa o User-Agent real do
  navegador instalado, sem a marca `HeadlessChrome`;
- **reconhece a página de verificação** e diz isso no log, em vez de dizer que a
  marcação mudou;
- ao ser barrado, **pausa, refaz a sessão e tenta uma vez**; insistir além disso só
  piora a reputação do IP.

Consequência prática: **não diminua `intervalo_requisicao_ms` nem encurte demais o
intervalo entre ciclos.** Uma rodada por hora com `/ofertas` como fonte principal e
algumas buscas por termo funciona bem. Rodar de 5 em 5 minutos derruba as buscas por
termo em pouco tempo.

Se a API pública do ML estiver acessível (ou você tiver `ML_ACCESS_TOKEN`), o modo
`auto` a usa primeiro — ela é mais rápida e traz `category_id`, o que deixa a comissão
mais precisa.

### Diferença entre as fontes

Os cards de `/ofertas` **não trazem nota nem frete grátis** — só as listas de busca
trazem. Por isso `filtros.avaliacoes_minimas` vem em `0`: se você exigir avaliações,
tudo que vier de `/ofertas` é descartado. `avaliacao_minima` é seguro, porque produto
sem nota nenhuma não é reprovado por ela.

---

## Link de afiliado

Dois modos, em `config/config.php` → `afiliado.modo`:

**`modelo`** (padrão) — monta a URL por template, sem login:

```
{url}?matt_word={tag}&matt_tool={ferramenta}&forceInApp=true
```

`tag` e `ferramenta` vêm do `.env` (`ML_AFILIADO_TAG`, `ML_AFILIADO_FERRAMENTA`).

**`linkbuilder`** — gera pela tela oficial
([Link Builder](https://www.mercadolivre.com.br/afiliados/linkbuilder#hub)).

A diferença importa: o link real do Mercado Livre carrega um parâmetro `ref` com um
token opaco, gerado do lado deles a cada link. **Não dá para reproduzir por
concatenação de string** — só a própria tela devolve o link completo. Se a atribuição
da comissão depender do `ref`, o modo `modelo` não credita.

**Medido na conta real, e o motivo do padrão ser `modelo`:** o Link Builder está
automatizado e funciona (12s para gerar, com cache), mas com o canal
`play_connect_mobile` tanto o link curto (`meli.la/...`) quanto o completo apontam para
`mercadolivre.com.br/social/play_connect_mobile` — o **perfil social** do afiliado, não
a página do produto. É um clique a mais entre a mensagem e a compra. Vale reavaliar se
a conta ganhar um canal do tipo "Links".

O Link Builder exige login, e a tela usa reCAPTCHA — que existe justamente para não ser
resolvido por automação. Então o login é feito por você, uma vez:

```bash
php bin/mlgroup ml-login          # abre o navegador; você entra e fecha a janela
php bin/mlgroup link <url>        # testa a geração de um link
php bin/mlgroup afiliado          # mostra como o link está sendo montado
```

A sessão fica salva em `storage/navegador-ml/` e o modo headless a reaproveita. Cada
link gerado vai para cache no banco — a tela é lenta e não há motivo para pedir duas
vezes o link do mesmo anúncio. Se o Link Builder falhar, o sistema cai para o modo
`modelo` e registra o aviso: link imperfeito não impede o envio da oferta.

Se a detecção automática dos campos da tela falhar, `php bin/mlgroup link <url>`
imprime os campos e botões que encontrou — preencha `afiliado.seletor_campo` e
`afiliado.seletor_botao` com base nisso.

---

## Perfil de nicho

O maior problema de um bot de ofertas não é achar desconto — é achar desconto do que
o grupo usa. A categoria "Ferramentas" do Mercado Livre mistura roçadeira, motosserra,
soprador de folhas e serra para madeira com as ferramentas de oficina.

[`config/nicho.php`](config/nicho.php) resolve isso classificando cada produto **pelo
título**, em três listas:

| Lista | Efeito |
|---|---|
| `essenciais` | a ferramenta ou o equipamento em si, de qualquer ramo — furadeira, serra circular, roçadeira, betoneira, multímetro, inversora de solda. Relevância **1,0** |
| `apoio` | acessório, consumível ou item de bancada — brocas, discos, lixas, EPI, extensão. Relevância **0,55** |
| `bloqueados` | parece ferramenta pelo termo mas não é — miniatura, brinquedo, camiseta, capa protetora |

O que não casa com nada é descartado (`exigir_relevancia`), e a relevância entra na
pontuação com peso `nicho` — por isso uma furadeira com 30% de desconto passa na frente
de um jogo de brocas com 50%.

**Por que pelo título e não pela categoria do ML:** a página `/ofertas`, que é a fonte
de coleta mais confiável, não informa a categoria de cada card. O título sempre vem, e
ferramenta se descreve no próprio nome.

**O casamento é por palavra, não por trecho literal.** Título de anúncio não é frase:
`lavadora de alta pressao` precisa encontrar *"Lavadora Alta Pressão Lav1300"* (sem o
"de") e `serra marmore` precisa encontrar *"Serra Makita 4100nh3zx Mármore"* (palavras
separadas). Então um termo casa quando **todas as suas palavras significativas**
aparecem no título, em qualquer ordem — preposições são ignoradas e o plural simples é
aceito. O termo com mais palavras vence, para `serra circular` ganhar de `serra`.

Medido sobre 86 produtos reais já coletados: **86 dentro do nicho**, com miniatura,
capa, camiseta e brinquedo continuando de fora.

Para mudar de ramo (marcenaria, construção, jardinagem) troque as listas desse arquivo.
Nenhum código muda.

---

## Não repetir e ritmo de publicação

**Não repetir.** O `ml_id` não basta: o Mercado Livre publica cada variação de cor ou
voltagem como anúncio separado, com ID próprio e título idêntico. Foi o caso real das
serras MLB15462505 e MLB15462506, que apareceram lado a lado no ranking.

Cada produto ganha uma **assinatura** (o título normalizado). Ela é usada em três
pontos: ao coletar (fica a variação mais barata), ao filtrar (bloqueia o que já foi
publicado) e na fila (não enfileira duas variações do mesmo item).

**Diversidade.** Não basta não repetir o mesmo produto: a fila é ordenada por
pontuação, e produto do mesmo tipo pontua parecido — o resultado natural são várias
parafusadeiras seguidas, quase todas da mesma marca. Duas regras espalham a sequência:

| Regra | Padrão |
|---|---|
| `repetir_tipo_apos` | o mesmo tipo (parafusadeira, lavadora, esmerilhadeira) só volta depois de 5 envios |
| `repetir_marca_apos` | a mesma marca só volta depois de 3 envios |

O **tipo** vem do termo do nicho que o produto casou. A **marca** é reconhecida no
título por [`config/marcas.php`](config/marcas.php) — necessário porque a página
`/ofertas` do ML não traz o campo de marca nos cards (dos 86 produtos coletados, zero
tinham marca preenchida).

Quem é adiado não é descartado: volta se a variedade acabar antes do lote. Ficar em
silêncio seria pior — se a fila só tem lavadora, não há diversidade a criar, só a
ordem a espalhar. Medido sobre o estoque real: **10 tipos distintos em 12 envios**
contra 9 sem a regra, com o mais repetido caindo de 3× para 2×.

**Ritmo.** Publicar de 10 em 10 minutos não pode significar varrer o Mercado Livre de
10 em 10 minutos — isso derruba as buscas no anti-bot rapidamente. Por isso existe uma
**fila**:

```
coleta (a cada 60 min) ──enche──► fila ──1 item a cada 10 min──► grupo
```

- `agenda.hora_inicio` / `agenda.hora_fim` → janela de envio (09:00–22:00)
- `agenda.intervalo_minutos` → de quanto em quanto tempo o ciclo roda (10)
- `envio.max_por_execucao` → quantas ofertas por ciclo (1)
- `coleta.intervalo_minutos` → de quanto em quanto tempo recoleta (60)
- `envio.validade_horas` → descarta oferta coletada há mais tempo que isso (12)

Se a fila esvaziar antes da hora, o ciclo recoleta mesmo fora do prazo.

A fila **revalida cada item contra o filtro atual** na hora de entregar, e não contra
o que valia quando o produto foi coletado. Sem isso, apertar o teto de preço ou trocar
de nicho deixaria a fila publicando o que já não deveria mais sair.

---

## Filtros disponíveis

Em `config/config.php` → `filtros`:

| Filtro | Efeito |
|---|---|
| `desconto_minimo` / `desconto_maximo` | faixa de desconto aceita — o teto derruba preço falso |
| `comissao_minima` | percentual mínimo de afiliado |
| `ganho_minimo` | reais mínimos por venda |
| `preco_minimo` / `preco_maximo` | faixa de preço — hoje **R$ 30 a R$ 300** (0 no teto = sem limite) |
| `avaliacao_minima` | nota mínima (ignorado se o produto não tem avaliação) |
| `avaliacoes_minimas` | quantidade mínima de avaliações |
| `exigir_frete_gratis` / `exigir_full` | só produtos com frete grátis / entrega FULL |
| `palavras_bloqueadas` | corta capa, adesivo, usado, recondicionado... |
| `palavras_obrigatorias` | se preenchido, o título precisa bater com alguma |
| `dias_sem_repetir` | não republica a mesma oferta nesse período |
| `queda_para_reenviar` | % de queda que libera republicar antes do prazo |

Além disso, o **detector de desconto falso** compara o preço "de" anunciado com a
mediana que aquele anúncio realmente praticou. Ele começa a agir depois de 3 coletas do
mesmo produto — ou seja, fica melhor com o tempo.

---

## Pontuação

Nota de 0 a 100 que define a ordem de publicação. Cada critério vira um valor entre 0 e
1 e entra pela média ponderada de `config/config.php` → `pontuacao.pesos`:

| Critério | O que mede |
|---|---|
| `desconto` | % de desconto, saturando no teto configurado |
| `comissao` | percentual de afiliado |
| `ganho` | reais por venda |
| `reputacao` | nota ponderada pela quantidade de avaliações |
| `popularidade` | quantidade vendida, em escala logarítmica |
| `logistica` | frete grátis e FULL |
| `historico` | bônus quando é o menor preço já visto |
| `nicho` | o quanto o item é cara de oficina (peso alto por padrão) |

Suba `comissao`/`ganho` para priorizar faturamento; suba `desconto` para priorizar
engajamento do grupo.

---

## Mensagem

Os templates ficam em [`templates/`](templates/) e usam duas marcações:

```
{campo}                → substitui pelo valor
{?campo}...{/campo}    → só aparece quando o campo tem conteúdo
```

Campos disponíveis: `titulo`, `preco`, `preco_original`, `desconto`, `economia`,
`link`, `comissao`, `ganho`, `marca`, `vendedor`, `avaliacao`, `total_avaliacoes`,
`vendidos`, `frete_gratis`, `full`, `menor_preco`, `termometro`, `pontuacao`, `data`,
`hora`.

Dois modos de envio em `config/config.php` → `envio.modo`:

- `individual` — uma mensagem por oferta (preview de link melhor)
- `lote` — uma mensagem com as N melhores (`templates/cabecalho.txt` + `item.txt`)

---

## Estrutura

```
bin/mlgroup              CLI
ponte/servidor.js        ponte local com o WhatsApp Web (Node + Baileys)
config/                  config.php · buscas.php · comissoes.php
templates/               textos das mensagens
src/
  Support/               Env, Config, Logger, Http, Str, CertificadoRaiz
  Database/              conexão SQLite e migrações
  Model/Produto.php      produto normalizado
  Scraper/               ColetorApi, ColetorNavegador
    Navegador/           ChromeHeadless (DevTools), WebSocket, ParserMercadoLivre
  Afiliado/              TabelaComissao, LinkAfiliado
  Analise/               Filtro, Pontuador, HistoricoPreco
  Mensagem/Montador.php  renderização dos templates
  Whatsapp/              Ponte, GerenciadorPonte, EvolutionApi, ZApi,
                         WppConnect, Simulado
  App/                   Cacador, Publicador, Ciclo, Agendador
  Cli/                   Console, ComandosWhatsapp, Saida
tests/smoke.php          autoteste
storage/                 banco, logs, cache e sessao do WhatsApp (fora do git)
```

---

## Problemas comuns

| Sintoma | Causa provável |
|---|---|
| `unable to get local issuer certificate` | CA bundle — veja a seção de certificados |
| `O ML devolveu pagina de verificacao de trafego` | acesso frequente demais; aumente o intervalo |
| `Nenhum card reconhecido no HTML` | o ML mudou a marcação; ajuste os XPath em `ParserMercadoLivre` |
| `Chrome/Edge nao encontrado` | informe o caminho em `config.navegador.executavel` |
| Nada é aprovado | rode com `--verbose`; quase sempre é `desconto_minimo` alto demais |
| `Nenhum grupo configurado` | `WHATSAPP_GRUPOS` vazio no `.env` |
| `Gateway de WhatsApp desconectado` | rode `php bin/mlgroup conectar` e leia o QR |
| `Node.js 20+ nao encontrado` | instale em [nodejs.org](https://nodejs.org) — a ponte precisa dele |
| `Dependencias da ponte ausentes` | `php bin/mlgroup instalar-ponte` |
| A ponte não sobe | veja `storage/logs/ponte.log` |
| QR expira antes de escanear | normal, ele se renova sozinho a cada ~20s |
