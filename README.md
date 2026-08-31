# ml-group

Acha ofertas de **ferramentas** no Mercado Livre e na Shopee e publica sozinho no seu
**grupo de WhatsApp**. Ele procura nas duas lojas, calcula o desconto e a comissão de
verdade, joga fora o que não presta e manda as melhores, no horário que você definir.

<img width="1894" height="949" alt="image" src="https://github.com/user-attachments/assets/96e69be4-7fcf-4cf4-b867-00efa35a5a8b" />

---

## Colocar no ar

Instale o [Docker Desktop](https://www.docker.com/products/docker-desktop/) e abra o
terminal dentro desta pasta. São quatro comandos:

```bash
copy .env.example .env      # só se ainda não existir (Mac/Linux: cp .env.example .env)

docker compose build        # monta tudo (uns 5 minutos, só a primeira vez)
docker compose up -d        # liga
docker compose exec mlgroup php bin/mlgroup conectar
```

O último comando desenha um **QR code no terminal**. No celular:
**WhatsApp → Aparelhos conectados → Conectar um aparelho**, aponte a câmera. Ele então
lista seus grupos numerados — digite o número do grupo e pronto, está funcionando.

> Já usava o sistema nesta máquina e quer manter o histórico? Pare o que está rodando
> no Windows e, antes do `up -d`, rode `docker compose run --rm importar` — ele leva
> banco, sessão do WhatsApp e ajustes para dentro do Docker.

**Sem Docker** (aí você precisa de PHP 8.1+, Node 20+ e Chrome instalados na
máquina): `php bin/mlgroup conectar` faz exatamente o mesmo, e o painel sobe com
`php bin/mlgroup painel`. Os detalhes estão no [manual](MANUAL.md#instalação).

---

## O painel

**http://127.0.0.1:8321** — é por aqui que você mexe em tudo: o que procurar, o preço
que aceita, o horário de publicar, o texto da mensagem. Ele abre junto com o sistema,
não precisa de comando.

O que você salva no painel vale no ciclo seguinte, sem reiniciar nada.

---

## Dia a dia

Todos os comandos começam com `docker compose exec mlgroup php bin/mlgroup`:

| Para | Comando |
|---|---|
| ver se está tudo de pé | `... status` |
| publicar uma oferta agora | `... ciclo` |
| ver o ranking sem publicar | `... analisar` |
| ver como a mensagem vai chegar | `... previa` |
| trocar de grupo | `... grupos` |
| ler o QR de novo | `... conectar` |
| testar a Shopee | `... shopee` |

E fora disso:

```bash
docker compose logs -f mlgroup   # acompanhar o que está acontecendo
docker compose stop              # parar
docker compose up -d             # ligar de novo
```

O sistema volta sozinho quando o computador reinicia, desde que o Docker Desktop
abra junto.

---

## Ligar a Shopee (opcional)

De fábrica ele procura só no Mercado Livre. A Shopee entra em três passos:

1. Na **Central de Afiliados da Shopee**, peça acesso à **Open API** e gere as
   credenciais (AppId e Secret). O acesso é liberado por eles, conta a conta — ter
   conta de afiliado não basta.
2. No painel, aba **Configuração → Shopee**, cole as duas e salve.
3. Confira: `docker compose exec mlgroup php bin/mlgroup shopee`

Se aparecer **"do not have access to the Shopee Affiliate Open API"** (erro 10035), as
credenciais estão certas mas a conta ainda não foi liberada — é com o time de afiliados
da Shopee, não tem ajuste no sistema que resolva.

Depois, em [`config/buscas.php`](config/buscas.php), descomente os exemplos da Shopee
no fim do arquivo (ou marque `'loja' => 'shopee'` em qualquer busca sua).

As ofertas das duas lojas entram na mesma fila, passam pelos mesmos filtros e vão para
o mesmo grupo. A vantagem da Shopee: ela informa a comissão real de cada anúncio e já
devolve o link com o seu rastreio — não precisa de tabela de comissão nem de login.

---

## Duas coisas para saber antes

**1. Use um chip dedicado, nunca o seu pessoal.** Publicar em grupo exige uma conexão
com o WhatsApp Web (a API oficial da Meta não envia para grupos, e isso não tem
contorno). Automação nesse caminho pode levar ao bloqueio do número.

**2. Confira a tabela de comissões do Mercado Livre.** Ele não informa a comissão por
API, e ela muda por categoria e campanha. O que o sistema usa está em
[`config/comissoes.php`](config/comissoes.php) — compare com a sua Central de Afiliados
e corrija, senão ele erra junto na hora de escolher as ofertas. (Na Shopee isso não
existe: a comissão vem pronta da API.)

---

## Deu problema?

| O que aparece | O que fazer |
|---|---|
| Nada é publicado | rode `... analisar --verbose`; quase sempre o desconto mínimo está alto demais |
| `Gateway de WhatsApp desconectado` | rode `... conectar` e leia o QR |
| `Nenhum grupo configurado` | rode `... grupos` e escolha um |
| `pagina de verificacao de trafego` | o ML barrou por excesso de acesso; aumente o intervalo no painel |
| O painel não abre | `docker compose logs mlgroup` mostra o motivo |
| QR some antes de você escanear | é normal, ele se renova a cada 20 segundos |

---

## Quer entender o que ele faz por dentro?

O [manual completo](MANUAL.md) explica cada peça: como a coleta escapa do anti-bot,
como a pontuação escolhe o que publicar, o perfil de nicho, os filtros, o link de
afiliado e a estrutura do código. O guia do Docker em detalhe está no
[LEIA-ME-DOCKER.txt](LEIA-ME-DOCKER.txt).
