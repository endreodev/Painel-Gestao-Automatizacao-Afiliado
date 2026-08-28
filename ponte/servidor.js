/**
 * Ponte local entre o ml-group (PHP) e o WhatsApp Web.
 *
 * Sobe um servidor HTTP simples em 127.0.0.1 que o lado PHP consulta. Cuida da
 * conexao, do QR code e da sessao - a sessao fica salva em storage/, entao o QR
 * so e pedido na primeira vez ou depois de desconectar pelo celular.
 *
 * Endpoints:
 *   GET  /status         estado da conexao + QR em ASCII quando ha um pendente
 *   GET  /grupos         grupos de que o numero participa
 *   POST /enviar         { destino, texto }
 *   POST /enviar-imagem  { destino, imagem, legenda }
 *   POST /sair           encerra a sessao (novo QR na proxima vez)
 *
 * Roda sozinho: node ponte/servidor.js
 * Normalmente quem o inicia e o proprio PHP (comando `conectar`).
 */

import { createServer } from 'node:http';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { rm } from 'node:fs/promises';

import baileys, { DisconnectReason, useMultiFileAuthState, fetchLatestBaileysVersion } from '@whiskeysockets/baileys';
import qrcode from 'qrcode-terminal';

const makeWASocket = baileys.default ?? baileys;

const RAIZ = join(dirname(fileURLToPath(import.meta.url)), '..');
const PASTA_SESSAO = join(RAIZ, 'storage', 'whatsapp-sessao');
const PORTA = Number(process.env.MLG_PONTE_PORTA || 8787);

/** Baileys espera um logger no formato do pino; este apenas engole tudo. */
const silencioso = {
    level: 'silent',
    child: () => silencioso,
    trace() {}, debug() {}, info() {}, warn() {}, error() {}, fatal() {},
};

const estado = {
    conectado: false,
    numero: '',
    qr: '',
    qrAscii: '',
    ultimoErro: '',
    encerrando: false,
};

let socket = null;

async function conectar() {
    const { state, saveCreds } = await useMultiFileAuthState(PASTA_SESSAO);
    const { version } = await fetchLatestBaileysVersion();

    socket = makeWASocket({
        version,
        auth: state,
        logger: silencioso,
        browser: ['ml-group', 'Chrome', '1.0.0'],
        syncFullHistory: false,
        markOnlineOnConnect: false,

        /*
         * Monta a previa do link (titulo, descricao e foto do produto) quando o
         * texto tem uma URL. E o que permite mandar a oferta como TEXTO e ainda
         * assim mostrar a imagem: no iPhone, link dentro de legenda de imagem
         * nao vira link tocavel, mas em mensagem de texto vira.
         *
         * Depende do pacote link-preview-js.
         */
        generateHighQualityLinkPreview: true,
    });

    socket.ev.on('creds.update', saveCreds);

    socket.ev.on('connection.update', (evento) => {
        const { connection, lastDisconnect, qr } = evento;

        if (qr) {
            estado.qr = qr;

            // guarda o desenho pronto para o PHP so imprimir
            qrcode.generate(qr, { small: true }, (arte) => {
                estado.qrAscii = arte;
            });

            console.log('\nEscaneie o QR code abaixo no WhatsApp do celular:\n');
            qrcode.generate(qr, { small: true });
            console.log('\nWhatsApp > Aparelhos conectados > Conectar um aparelho\n');
        }

        if (connection === 'open') {
            estado.conectado = true;
            estado.qr = '';
            estado.qrAscii = '';
            estado.ultimoErro = '';
            estado.numero = (socket.user?.id || '').split(':')[0];

            console.log('Conectado como ' + estado.numero);
        }

        if (connection === 'close') {
            estado.conectado = false;

            const motivo = lastDisconnect?.error?.output?.statusCode;
            const deslogado = motivo === DisconnectReason.loggedOut;

            estado.ultimoErro = deslogado
                ? 'sessao encerrada no celular'
                : (lastDisconnect?.error?.message || 'conexao caiu');

            if (estado.encerrando) {
                return;
            }

            if (deslogado) {
                // credenciais nao valem mais: limpa para o proximo QR valer
                console.log('Sessao encerrada no celular. Rode o comando conectar de novo.');
                rm(PASTA_SESSAO, { recursive: true, force: true }).catch(() => {});

                return;
            }

            console.log('Conexao caiu (' + estado.ultimoErro + '), reconectando...');
            setTimeout(() => conectar().catch(registrarFalha), 3000);
        }
    });
}

function registrarFalha(erro) {
    estado.ultimoErro = erro?.message || String(erro);
    console.error('Falha na ponte: ' + estado.ultimoErro);
}

/**
 * Este numero e admin do grupo? null quando nao da para saber.
 *
 * So importa em grupo restrito a admins ("somente administradores podem
 * enviar"): ali, quem nao e admin tem a mensagem engolida em silencio - o envio
 * volta como aceito e nada aparece. Descobrir isso na hora de escolher o grupo
 * evita um canal que parece configurado e nunca publica.
 *
 * Devolve null, e nao false, quando o id do participante nao bate com nenhum
 * formato conhecido (o WhatsApp vem migrando de numero para LID): dizer "voce
 * nao e admin" sem certeza seria pior do que admitir a duvida.
 */
function souAdminDe(grupo) {
    if (!Array.isArray(grupo.participants)) {
        return null;
    }

    /*
     * Compara pelos DOIS identificadores. O WhatsApp migrou a lista de
     * participantes para LID ("49662857891964@lid") e nao devolve mais o numero
     * de telefone ali - comparar so por numero nunca encontrava ninguem, e todo
     * grupo respondia "nao da para saber". O lid do proprio numero vem em
     * socket.user.lid; o sufixo ":25" e o aparelho e precisa sair.
     */
    const meus = [socket?.user?.id, socket?.user?.lid, estado.numero]
        .map((valor) => String(valor || '').split(':')[0].split('@')[0])
        .filter((valor) => valor !== '');

    if (meus.length === 0) {
        return null;
    }

    for (const participante of grupo.participants) {
        const id = String(participante?.id || '').split(':')[0].split('@')[0];

        if (meus.includes(id)) {
            return participante.admin === 'admin' || participante.admin === 'superadmin';
        }
    }

    return null;
}

async function listarGrupos() {
    if (!socket || !estado.conectado) {
        return [];
    }

    const grupos = await socket.groupFetchAllParticipating();

    return Object.values(grupos)
        .map((grupo) => ({
            id: grupo.id,
            nome: grupo.subject || '(sem nome)',
            participantes: Array.isArray(grupo.participants) ? grupo.participants.length : 0,
            somenteAdmin: grupo.announce === true,
            souAdmin: souAdminDe(grupo),
        }))
        .sort((a, b) => a.nome.localeCompare(b.nome, 'pt-BR'));
}

/** Aceita "1203...@g.us", "1203..." ou um numero de telefone. */
function normalizarDestino(destino) {
    const bruto = String(destino || '').trim();

    if (bruto.includes('@')) {
        return bruto;
    }

    const somenteDigitos = bruto.replace(/\D/g, '');

    // ids de grupo tem 18 digitos ou mais; abaixo disso e telefone
    return somenteDigitos.length >= 18
        ? somenteDigitos + '@g.us'
        : somenteDigitos + '@s.whatsapp.net';
}

async function enviarTexto(destino, texto) {
    const enviado = await socket.sendMessage(normalizarDestino(destino), { text: String(texto) });

    return enviado?.key?.id || '';
}

async function enviarImagem(destino, imagem, legenda) {
    const enviado = await socket.sendMessage(normalizarDestino(destino), {
        image: { url: String(imagem) },
        caption: String(legenda || ''),
    });

    return enviado?.key?.id || '';
}

function responder(res, codigo, corpo) {
    const json = JSON.stringify(corpo);

    res.writeHead(codigo, {
        'Content-Type': 'application/json; charset=utf-8',
        'Content-Length': Buffer.byteLength(json),
    });
    res.end(json);
}

function lerCorpo(req) {
    return new Promise((resolve) => {
        let dados = '';

        req.on('data', (pedaco) => {
            dados += pedaco;

            // trava simples contra corpo gigante
            if (dados.length > 2_000_000) {
                req.destroy();
            }
        });

        req.on('end', () => {
            try {
                resolve(dados ? JSON.parse(dados) : {});
            } catch {
                resolve({});
            }
        });
    });
}

const servidor = createServer(async (req, res) => {
    const rota = (req.url || '/').split('?')[0];

    try {
        if (rota === '/status') {
            return responder(res, 200, {
                conectado: estado.conectado,
                numero: estado.numero,
                qr: estado.qr,
                qr_ascii: estado.qrAscii,
                erro: estado.ultimoErro,
            });
        }

        if (rota === '/grupos') {
            return responder(res, 200, await listarGrupos());
        }

        if (rota === '/enviar' && req.method === 'POST') {
            const corpo = await lerCorpo(req);

            if (!estado.conectado) {
                return responder(res, 503, { erro: 'WhatsApp desconectado' });
            }

            return responder(res, 200, { id: await enviarTexto(corpo.destino, corpo.texto) });
        }

        if (rota === '/enviar-imagem' && req.method === 'POST') {
            const corpo = await lerCorpo(req);

            if (!estado.conectado) {
                return responder(res, 503, { erro: 'WhatsApp desconectado' });
            }

            return responder(res, 200, {
                id: await enviarImagem(corpo.destino, corpo.imagem, corpo.legenda),
            });
        }

        if (rota === '/sair' && req.method === 'POST') {
            estado.encerrando = true;

            try {
                await socket?.logout();
            } catch {
                // sessao ja invalida: seguir e apagar assim mesmo
            }

            await rm(PASTA_SESSAO, { recursive: true, force: true });

            responder(res, 200, { ok: true });

            return setTimeout(() => process.exit(0), 300);
        }

        if (rota === '/encerrar' && req.method === 'POST') {
            estado.encerrando = true;
            responder(res, 200, { ok: true });

            return setTimeout(() => process.exit(0), 300);
        }

        return responder(res, 404, { erro: 'rota desconhecida' });
    } catch (erro) {
        registrarFalha(erro);

        return responder(res, 500, { erro: estado.ultimoErro });
    }
});

servidor.listen(PORTA, '127.0.0.1', () => {
    console.log('Ponte do WhatsApp ouvindo em http://127.0.0.1:' + PORTA);
});

conectar().catch(registrarFalha);
