import './styles/app.css';

const review = document.querySelector('#review');
const form = document.querySelector('#approve');
const status = document.querySelector('#status');

// The button waits for the picture, so nobody approves what they could not see.
document.querySelector('.frame')?.decode().then(
    () => form?.querySelector('button').removeAttribute('disabled'),
    () => { status.textContent = 'The picture did not load. Reload the page.'; },
);

form?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const response = await fetch(form.action, { method: 'POST', body: new FormData(form), redirect: 'manual' });
    if (response.status === 422) {
        status.textContent = (await response.json()).error;
        return;
    }
    location.reload();
});

// The server decides what this page shows, so any change in the game reloads it.
new EventSource(review.dataset.hubUrl).onmessage = () => location.reload();
