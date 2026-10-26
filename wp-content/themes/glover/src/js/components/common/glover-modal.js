// Basic setup for a modal with support for buttons that open and close it.
// Basic modal style is in _glover-modal.scss.

const openClass = 'open';
const modalOpenClass = 'modal-open';

const setUpModals = () => {
    let htmlTag = document.querySelector('html');

    // open buttons
    document.querySelectorAll('.glover-modal-opener').forEach(function (modalOpenerElement) {
        const modalId = modalOpenerElement.getAttribute('data-modal-id');
        let modalElement = document.querySelector(`.glover-modal[data-modal-id="${modalId}"]`);
        if (modalElement) {
            modalOpenerElement.addEventListener("click", function () {
                if (modalElement.classList.contains(openClass)) {
                    modalElement.classList.remove(openClass)
                    htmlTag.classList.remove(modalOpenClass)
                } else {
                    modalElement.classList.add(openClass)
                    htmlTag.classList.add(modalOpenClass)
                }
            });
        }
    });

    // close buttons
    document.querySelectorAll('.glover-modal').forEach(function (modalElement) {
        let closeButtonElement = modalElement.querySelector('.glover-modal-closer');
        if (closeButtonElement) {
            closeButtonElement.addEventListener("click", function () {
                modalElement.classList.remove(openClass)
                htmlTag.classList.remove(modalOpenClass)
            });
        }
    });
}

export default setUpModals;
