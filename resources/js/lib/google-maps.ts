export interface GoogleMapsNamespace {
    Map: new (el: HTMLElement, opts?: Record<string, unknown>) => GoogleMapInstance;
    Marker: new (opts?: Record<string, unknown>) => GoogleMarkerInstance;
    InfoWindow: new (opts?: Record<string, unknown>) => GoogleInfoWindowInstance;
    Polyline: new (opts?: Record<string, unknown>) => GooglePolylineInstance;
    LatLngBounds: new () => GoogleLatLngBoundsInstance;
    Point: new (x: number, y: number) => unknown;
    Size: new (width: number, height: number) => unknown;
    SymbolPath: Record<string, unknown>;
}

export interface GoogleMapInstance {
    setCenter: (center: { lat: number; lng: number }) => void;
    panTo: (center: { lat: number; lng: number }) => void;
    setZoom: (zoom: number) => void;
    getZoom: () => number | undefined;
    fitBounds: (bounds: GoogleLatLngBoundsInstance, padding?: number | Record<string, number>) => void;
    setOptions: (opts: Record<string, unknown>) => void;
}

export interface GoogleMarkerInstance {
    setMap: (map: GoogleMapInstance | null) => void;
    setPosition: (pos: { lat: number; lng: number }) => void;
    setIcon: (icon: unknown) => void;
    setZIndex: (zIndex: number) => void;
    setTitle: (title: string) => void;
    addListener: (event: string, handler: () => void) => { remove: () => void };
}

export interface GoogleInfoWindowInstance {
    setContent: (content: string | HTMLElement) => void;
    open: (opts: { map: GoogleMapInstance; anchor: GoogleMarkerInstance }) => void;
    close: () => void;
}

export interface GooglePolylineInstance {
    setMap: (map: GoogleMapInstance | null) => void;
    setPath: (path: Array<{ lat: number; lng: number }>) => void;
}

export interface GoogleLatLngBoundsInstance {
    extend: (pos: { lat: number; lng: number }) => void;
}

declare global {
    interface Window {
        google?: {
            maps?: GoogleMapsNamespace;
        };
    }
}

let loadPromise: Promise<GoogleMapsNamespace> | null = null;

export function loadGoogleMaps(apiKey: string): Promise<GoogleMapsNamespace> {
    if (typeof window === "undefined") {
        return Promise.reject(new Error("Google Maps requires a browser environment."));
    }
    if (window.google?.maps) {
        return Promise.resolve(window.google.maps);
    }
    if (!apiKey) {
        return Promise.reject(new Error("Missing GOOGLE_MAP_API_KEY configuration."));
    }
    if (loadPromise) {
        return loadPromise;
    }

    loadPromise = new Promise<GoogleMapsNamespace>((resolve, reject) => {
        const existing = document.querySelector<HTMLScriptElement>('script[data-google-maps-loader="true"]');
        if (existing) {
            existing.addEventListener("load", () => {
                if (window.google?.maps) resolve(window.google.maps);
                else reject(new Error("Google Maps failed to initialize."));
            });
            existing.addEventListener("error", () => reject(new Error("Failed to load Google Maps script.")));
            return;
        }

        const script = document.createElement("script");
        script.dataset.googleMapsLoader = "true";
        script.src = `https://maps.googleapis.com/maps/api/js?key=${encodeURIComponent(apiKey)}`;
        script.async = true;
        script.defer = true;
        script.onload = () => {
            if (window.google?.maps) {
                resolve(window.google.maps);
            } else {
                loadPromise = null;
                reject(new Error("Google Maps loaded without window.google.maps."));
            }
        };
        script.onerror = () => {
            loadPromise = null;
            reject(new Error("Failed to load Google Maps JavaScript API."));
        };
        document.head.appendChild(script);
    });

    return loadPromise;
}

export const GOOGLE_LIGHT_MAP_STYLES = [
    { featureType: "poi", stylers: [{ visibility: "off" }] },
    { featureType: "transit", stylers: [{ visibility: "off" }] },
];

export const GOOGLE_DARK_MAP_STYLES = [
    { elementType: "geometry", stylers: [{ color: "#1d221f" }] },
    { elementType: "labels.text.stroke", stylers: [{ color: "#1d221f" }] },
    { elementType: "labels.text.fill", stylers: [{ color: "#9ca59e" }] },
    { featureType: "administrative.locality", elementType: "labels.text.fill", stylers: [{ color: "#d4d8d2" }] },
    { featureType: "poi", stylers: [{ visibility: "off" }] },
    { featureType: "road", elementType: "geometry", stylers: [{ color: "#2c332f" }] },
    { featureType: "road", elementType: "geometry.stroke", stylers: [{ color: "#202522" }] },
    { featureType: "road", elementType: "labels.text.fill", stylers: [{ color: "#9ca59e" }] },
    { featureType: "road.highway", elementType: "geometry", stylers: [{ color: "#3b453f" }] },
    { featureType: "road.highway", elementType: "geometry.stroke", stylers: [{ color: "#252b27" }] },
    { featureType: "road.highway", elementType: "labels.text.fill", stylers: [{ color: "#cfd4ce" }] },
    { featureType: "transit", stylers: [{ visibility: "off" }] },
    { featureType: "water", elementType: "geometry", stylers: [{ color: "#111922" }] },
    { featureType: "water", elementType: "labels.text.fill", stylers: [{ color: "#5c7084" }] },
];
