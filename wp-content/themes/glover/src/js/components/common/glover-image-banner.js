import debounce from "../../utils/debounce";
import constants from "../../constants";

const handleImageBanners = () => {
    if (document.querySelector('.image-banner')) {
        // Image banner PHP setup loads mobile image eagerly and desktop image lazily by default.
        // If viewport is not mobile-sized, load desktop image eagerly and try to de-prioritize mobile image loading.
        if (window.innerWidth >= constants.screenWidthBreakpoints.sm) {
            let mobileBannerImages = document.getElementsByClassName('image-banner-image single-image-mobile') || [];
            for (let i = 0; i < mobileBannerImages.length; i++) {
                let mobileBannerImage = mobileBannerImages[i];
                mobileBannerImage.setAttribute('decoding', "async");
                mobileBannerImage.setAttribute('loading', "lazy");
                mobileBannerImage.setAttribute('fetchpriority', "low");
            }

            let desktopBannerImages = document.getElementsByClassName('image-banner-image single-image-desktop') || [];
            for (let k = 0; k < desktopBannerImages.length; k++) {
                let desktopBannerImage = desktopBannerImages[k];
                desktopBannerImage.setAttribute('decoding', "auto");
                desktopBannerImage.setAttribute('loading', "eager");
                desktopBannerImage.setAttribute('fetchpriority', "high");
            }
        }

        // handle video in image banner if present
        const imageBannerVideoElement = document.querySelector('#imageBannerVideo');
        if (imageBannerVideoElement) {
            const imageBannerVideoLoader = function () {
                const videoUrl = imageBannerVideoElement.getAttribute('data-video-url');
                let alreadyHasSource = imageBannerVideoElement.querySelector('source');
                if (window.innerWidth >= constants.screenWidthBreakpoints.sm && !alreadyHasSource) {
                    imageBannerVideoElement.innerHTML = `<source src="${videoUrl}">`;
                    const imageBannerVideoSource = imageBannerVideoElement.querySelector('source');
                    if (imageBannerVideoSource) {
                        // show video tag background fallback image after 1 second (to catch case where video fails to load).
                        let fallbackImageUrl =
                            document.querySelector('.image-banner-image.single-image-desktop').getAttribute('src');
                        window.setTimeout(function () {
                            imageBannerVideoElement.setAttribute('style', `background-image: url(${fallbackImageUrl})`)
                        }, 1000);
                    }
                }
            };

            const setupImageBannerVideoLoader = function () {
                // execute once on load
                imageBannerVideoLoader();

                // execute on resize, debounced
                window.addEventListener("resize", debounce(function () {
                    imageBannerVideoLoader();
                }, 250));
            }

            // execute setup
            setupImageBannerVideoLoader();
        }
    }
}

export default handleImageBanners;