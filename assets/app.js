import './styles/app.css';

const game = document.querySelector('#game');
const stage = document.querySelector('#stage');
const photo = document.querySelector('#photo');
const regions = document.querySelector('#regions');
const draft = document.querySelector('#draft');
const mark = document.querySelector('#mark');
const status = document.querySelector('#status');
const frame = document.querySelector('#frame');
const blocked = document.querySelector('#blocked');

let session = JSON.parse(game.dataset.session);
let box = null;

function percent(value, total) {
    return `${(100 * value) / total}%`;
}

function place(element, region) {
    const { width, height } = session.photo;
    element.style.left = percent(region.x, width);
    element.style.top = percent(region.y, height);
    element.style.width = percent(region.width, width);
    element.style.height = percent(region.height, height);
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
    regions.replaceChildren(...session.regions.map((region) => {
        const element = document.createElement('div');
        element.className = `region ${region.kind}`;
        element.dataset.mine = String(region.owner === Number(game.dataset.player));
        element.textContent = region.label;
        place(element, region);
        return element;
    }));
}

async function refresh() {
    const response = await fetch(game.dataset.sessionUrl);
    session = await response.json();
    // The page without a photo has no stage to update.
    if (session.photo && !stage) {
        location.reload();
        return;
    }
    render();
}

function pointInPhoto(event) {
    const bounds = photo.getBoundingClientRect();
    const scale = session.photo.width / bounds.width;
    return {
        x: Math.round(Math.min(Math.max(event.clientX - bounds.left, 0), bounds.width) * scale),
        y: Math.round(Math.min(Math.max(event.clientY - bounds.top, 0), bounds.height) * scale),
    };
}

function trackDrag() {
    let start = null;

    stage.addEventListener('pointerdown', (event) => {
        start = pointInPhoto(event);
        stage.setPointerCapture(event.pointerId);
        mark.hidden = true;
    });

    stage.addEventListener('pointermove', (event) => {
        if (!start) {
            return;
        }
        const point = pointInPhoto(event);
        box = {
            x: Math.min(start.x, point.x),
            y: Math.min(start.y, point.y),
            width: Math.abs(point.x - start.x),
            height: Math.abs(point.y - start.y),
        };
        draft.hidden = false;
        place(draft, box);
    });

    stage.addEventListener('pointerup', () => {
        start = null;
        mark.hidden = !box || box.width < 1 || box.height < 1;
    });
}

async function sendRegion(event) {
    event.preventDefault();
    const body = new FormData();
    body.set('_token', game.dataset.regionToken);
    body.set('kind', event.submitter.value);
    body.set('label', mark.elements.label?.value ?? '');
    Object.entries(box).forEach(([field, value]) => body.set(field, value));

    const response = await fetch(game.dataset.regionUrl, { method: 'POST', body });
    if (!response.ok) {
        status.textContent = (await response.json()).error;
        return;
    }
    box = null;
    draft.hidden = true;
    mark.hidden = true;
    mark.reset();
}

if (stage) {
    trackDrag();
    mark.addEventListener('submit', sendRegion);
}
new EventSource(game.dataset.hubUrl).onmessage = refresh;
render();
