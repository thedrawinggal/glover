const markerImageRelativePath = '/wp-content/themes/<TODO_THEME_NAME_GOES_HERE>/dist/images/map-marker.png';

const initializeMap = async () => {
    const mapContainer = document.getElementById("mapCanvas");
    if (mapContainer) {
        const {Map} = await google.maps.importLibrary("maps");
        const zoom = document.querySelector('.acf-map').getAttribute('data-zoom') || 16;

        // snazzy maps styles
        const mapStyles = [/*TODO paste Snazzy Maps style definition here if styling with Snazzy Maps*/];
        const mapOptions = {
            zoom: zoom,
            mapTypeId: google.maps.MapTypeId.ROADMAP,
            styles: mapStyles ? mapStyles : [],
            mapTypeControl: false,
            fullscreenControl: false,
            streetViewControl: false,
        };
        let cltv8LocationsMap = new Map(mapContainer, mapOptions);

        const bounds = new google.maps.LatLngBounds();
        const markers = document.querySelectorAll('.acf-map .marker');
        if (markers.length > 0) {
            markers.forEach(function (markerTag) {
                const marker = createMarker(cltv8LocationsMap, markerTag);
                bounds.extend(marker.position);
            });

            if (markers.length === 1) {
                cltv8LocationsMap.setCenter(bounds.getCenter());
            } else {
                cltv8LocationsMap.fitBounds(bounds);
            }
        } else {
            cltv8LocationsMap.fitBounds(bounds);
        }
    }
}

const createMarker = (cltv8LocationsMap, location) => {
    // Markers should be double-sized pngs.
    // The scaledSize is the dimensions we want the marker to display at.
    // The origin and anchor may need adjusting so we can center the marker correctly over its target regardless of how
    // far the map is zoomed. We would need to center "pin" and "dot" markers differently, so you'll need to figure this
    // out yourself per project. If the marker keeps pointing at its location regardless of map zoom distance, it is
    // set up correctly.
    let markerImagePath = window.location.protocol + '//' + window.location.host + markerImageRelativePath;
    const markerIcon = {
        url: markerImagePath,
        scaledSize: new google.maps.Size(40, 54),
        origin: new google.maps.Point(0, 0),
        anchor: new google.maps.Point(20, 54),
    };

    const markerLat = location.getAttribute('data-lat');
    const markerLng = location.getAttribute('data-lng');

    const marker = new google.maps.Marker({
        position: new google.maps.LatLng(markerLat, markerLng),
        map: cltv8LocationsMap,
        title: location.querySelector('.location-title').textContent,
        icon: markerIcon
    });

    if (location.innerHTML && location.innerHTML.trim().length) {
        const infoWindow = new google.maps.InfoWindow({maxWidth: 640});

        google.maps.event.addListener(marker, "click", function (event, needZoomingIn) {
            infoWindow.setContent(location.innerHTML);
            infoWindow.open(cltv8LocationsMap, marker);
        });
    }

    return marker;
}

export {initializeMap};
