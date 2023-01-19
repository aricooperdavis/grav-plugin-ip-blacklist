
addEventListener('DOMContentLoaded', (event) => {
    // Associate buttons with processInput function
    let buttons = document.querySelectorAll('.admin-block .button');
    buttons.forEach(button => {
        button.addEventListener('click', (event) => {
            processInput(button.id);
        });
    });

    // On-load AJAXs
    processInput('last-25');
    processInput('stats');
});

// Send AJAX requests to backend
function processInput(id) {

    // Show loaders, hide results
    _id = ( ['add','remove'].includes(id) ? 'search' : id );
    let loader = document.querySelector(`#${_id} .grav-loader`);
    loader.style.display = 'block';
    let block = document.querySelector(`#${_id} .results`);
    block.style.display = 'none';

    let ip = document.querySelector('#search-box').value;
    let body = JSON.stringify({
        action: id,
        ip: ip,
    });

    // Do AJAX
    let xhr = new XMLHttpRequest();
    xhr.open('POST', 'ip-blacklist/data.json', true)
    xhr.setRequestHeader('Content-type', 'application/json; charset=UTF-8');
    xhr.send(body);

    xhr.onload = () => {
        let response = JSON.parse(xhr.response);
        switch (id) {
            case 'last-25':
                block.innerHTML = response.map(ip => {
                    return `<p class="ip">&bull; <a href="https://www.abuseipdb.com/check/${ip}" target="_blank">${ip}</a></p>`;
                }).join('');
                break;

            case 'stats':
                block.innerHTML = Object.entries(response).map(([key, value]) => {
                    return `<p><span class="stat">${key}</span>: ${value}</p>`;
                }).join('');
                break;

            case 'search':
                response = parseInt(response);
                block.querySelector('#ip').textContent = ip;
                block.querySelector('.notices').className = `notices ${ ( response ? 'yellow' : 'green') }`;
                block.querySelector('#status').textContent = ( response ? 'found in' : 'not found in');
                block.querySelector('#add').className = ( response ? 'button disabled' : 'button');
                block.querySelector('#remove').className = ( response ? 'button' : 'button disabled');
                break;

            case 'add':
                response = parseInt(response);
                block.querySelector('#ip').textContent = ip;
                block.querySelector('.notices').className = `notices ${ ( response ? 'yellow' : 'red') }`;
                block.querySelector('#status').textContent = ( response ? 'added to' : 'could not be added to');
                block.querySelector('#add').className = ( response ? 'button disabled' : 'button');
                block.querySelector('#remove').className = ( response ? 'button' : 'button disabled');
                break;

            case 'remove':
                response = parseInt(response);
                block.querySelector('#ip').textContent = ip;
                block.querySelector('.notices').className = `notices ${ ( response ? 'yellow' : 'red') }`;
                block.querySelector('#status').textContent = ( response ? 'removed from' : 'could not be removed from');
                block.querySelector('#add').className = ( response ? 'button' : 'button disabled');
                block.querySelector('#remove').className = ( response ? 'button disabled' : 'button');
                break;
        }
        // Hide loader
        loader.style.display = 'none';
        block.style.display = 'block';
    };
}