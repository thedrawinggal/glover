// This is a replacement for jQuery $(document).ready(fn); - if we are only using jQuery for this function and for
// element selection, we can use vanilla JS instead and not depend on jQuery for our custom JS bundle. This may mean we
// can avoid loading jQuery entirely and get a performance boost.
let documentIsReady = false;
let onDocumentReady = function (callback) {
    // If document is not ready yet, run the function when it is
    document.addEventListener("DOMContentLoaded", () => {
        if (documentIsReady === false) {
            documentIsReady = true;
            callback();
        }
    });

    // If document is already ready, run the function
    if (
        (document.readyState === "interactive" || document.readyState === "complete") &&
        documentIsReady === false
    ) {
        documentIsReady = true;
        callback();
    }
}

export default onDocumentReady;