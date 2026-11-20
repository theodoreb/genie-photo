import './styles/tokens.css';
import './styles/app.css';

const game = document.querySelector('#game');
const stage = document.querySelector('#stage');
const regions = document.querySelector('#regions');
const status = document.querySelector('#status');
const frame = document.querySelector('#frame');
const blocked = document.querySelector('#blocked');
const objects = document.querySelector('#objects');
const proposals = document.querySelector('#proposals');
const approve = document.querySelector('#approve');
const approval = document.querySelector('#approval');
const download = document.querySelector('#download');
const guide = document.querySelector('#guide');

let session = JSON.parse(game.dataset.session);

function percent(value, total) {
    return `${(100 * value) / total}%`;
}

function place(element, box) {
    const { width, height } = session.photo;
    element.style.left = percent(box.x, width);
    element.style.top = percent(box.y, height);
    element.style.width = percent(box.width, width);
    element.style.height = percent(box.height, height);
    return element;
}

function markElement(region) {
    const element = document.createElement('div');
    element.className = `region ${region.kind}`;
    element.textContent = region.label;
    element.dataset.mine = String(region.owner === Number(game.dataset.player));
    return place(element, region.head ?? region);
}

function proposalElement(box) {
    const element = document.createElement('button');
    element.type = 'button';
    element.className = 'region proposal';
    element.textContent = 'me?';
    element.setAttribute('aria-label', `This is me: ${box.label}`);
    element.addEventListener('click', () => claim(box));
    return place(element, box.head);
}

function render() {
    status.textContent = `Revision ${session.revision}`;
    if (!stage) {
        return;
    }
    frame.hidden = !session.frame.ready;
    blocked.hidden = session.frame.ready;
    if (session.frame.ready) {
        frame.src = game.dataset.frameUrl + session.revision;
    } else {
        blocked.textContent = `No frame fits these wishes: ${session.frame.players.join(', ')}.`;
    }
    renderApproval();
    guide.hidden = session.proposals.length > 0 || session.regions.length > 0;
    proposals.replaceChildren(...session.proposals.map(proposalRow));
    objects.replaceChildren(...session.regions.filter((region) => region.kind === 'object').map(objectRow));
    regions.replaceChildren(...session.regions.map(markElement), ...session.proposals.map(proposalElement));
}

function renderApproval() {
    const { export: picture } = session;
    approve.hidden = !picture || picture.approved_by_you || picture.final;
    download.hidden = !picture?.final;
    if (!picture) {
        approval.textContent = '';
        return;
    }
    download.href = game.dataset.frameUrl + session.revision;
    approval.textContent = picture.final
        ? 'Everyone approved this picture.'
        : `Waiting for: ${picture.waiting_for.join(', ')}`;
}

function button(label, action) {
    const element = document.createElement('button');
    element.textContent = label;
    element.addEventListener('click', action);
    return element;
}

function proposalRow(box) {
    const row = document.createElement('li');
    row.textContent = `${box.label} `;
    row.append(button('This is me', () => claim(box)));
    if (session.is_uploader) {
        row.append(' ', button('Not playing', () => {
            const body = new FormData();
            body.set('proposal', box.id);
            post(game.dataset.dismissUrl, body);
        }));
    }
    return row;
}

function claim(box) {
    const body = new FormData();
    body.set('person', box.id);
    post(game.dataset.claimUrl, body);
}

function objectRow(region) {
    const row = document.createElement('li');
    const suggested = region.suggested_by.length ? `, suggested by ${region.suggested_by.join(', ')}` : '';
    row.textContent = `${region.label}: ${region.protected ? 'stays in the frame' : 'free'}${suggested} `;
    if (session.is_uploader) {
        row.append(button(region.protected ? 'Release' : 'Protect', () => setProtected(region)));
    }
    return row;
}

async function send(url, body) {
    body.set('_token', game.dataset.regionToken);
    const response = await fetch(url, { method: 'POST', body, redirect: 'manual' });
    // Only the sign-in page redirects here: the session or its token ended.
    if (response.type === 'opaqueredirect') {
        location.reload();
    }
    return response;
}

async function post(url, body) {
    const response = await send(url, body);
    if (response.status === 422) {
        status.textContent = (await response.json()).error;
    }
    return response.ok;
}

function setProtected(region) {
    const body = new FormData();
    body.set('object', region.id);
    body.set('protected', region.protected ? '0' : '1');
    post(game.dataset.protectUrl, body);
}

async function refresh() {
    const response = await fetch(game.dataset.sessionUrl, { redirect: 'manual' });
    if (!response.ok) {
        location.reload();
        return;
    }
    const next = await response.json();
    // The page without a photo has no stage, and a lower revision is a new session.
    if ((next.photo && !stage) || next.revision < session.revision) {
        location.reload();
        return;
    }
    session = next;
    render();
}

if (stage) {
    approve.addEventListener('click', () => {
        const body = new FormData();
        body.set('hash', session.export.hash);
        post(game.dataset.approveUrl, body);
    });
}
new EventSource(game.dataset.hubUrl).onmessage = refresh;
render();
