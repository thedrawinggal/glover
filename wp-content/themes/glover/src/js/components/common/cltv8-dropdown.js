// Adds support for dropdowns. Each dropdown's content displays with a max-height animation when the user clicks a
// button. Dropdowns close if users click anywhere outside them.
//
// Basic style is in _cltv8-dropdown.scss. Dropdowns for each individual project will probably need a lot more
// project-specific style applied.

const handleDropdowns = () => {
    const dropdownButtonElements = document.querySelectorAll('.cltv8-dropdown-button');
    const openClass = 'open';
    const bodyElement = document.querySelector('body');

    if (dropdownButtonElements) {
        dropdownButtonElements.forEach(function (dropdownButtonElement) {
            dropdownButtonElement.addEventListener("click", function () {
                const dropdownClicked = dropdownButtonElement.closest('.cltv8-dropdown');
                if (dropdownClicked.classList.contains(openClass)) {
                    dropdownClicked.classList.remove(openClass);
                } else {
                    dropdownClicked.classList.add(openClass);
                }
            });
        });

        bodyElement.addEventListener("click", function (event) {
            dropdownButtonElements.forEach(function (dropdownButtonElement) {
                const dropdownForButton = dropdownButtonElement.closest('.cltv8-dropdown');
                if (!dropdownForButton.contains(event.target)) {
                    if (dropdownForButton.classList.contains(openClass)) {
                        dropdownForButton.classList.remove(openClass);
                    }
                }
            });
        });
    }
}

export default handleDropdowns;
