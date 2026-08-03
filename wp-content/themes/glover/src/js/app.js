// If possible, do not import third-party JS libraries here; import them dynamically in the functions that use them at
// the point they're first needed. That way we avoid loading that JS before it's absolutely necessary, meaning we can
// get an initial page load performance boost.
import handleImageBanners from "./components/common/cltv8-image-banner";
import onDocumentReady from "./utils/onDocumentReady";

const app = () => {
    onDocumentReady(function () {
        // Capture scrollbar width in a CSS variable.
        // This simplifies CSS calculations that need to know the viewport width minus the scrollbar width.
        document.documentElement.style.setProperty(
            '--scrollbar-width', (window.innerWidth - document.documentElement.clientWidth) + 'px'
        );

        // Call functions here to set up page elements that require JS setup. Use dynamic imports in those functions
        // when possible.
        handleImageBanners();
    });
};

app();