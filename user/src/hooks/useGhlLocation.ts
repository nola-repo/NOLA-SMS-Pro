import { useEffect, useState } from 'react';
import { getAccountSettings, saveAccountSettings } from '../utils/settingsStorage';

export function useGhlLocation() {
    const [locationId, setLocationId] = useState<string | null>(getAccountSettings().ghlLocationId || null);

    useEffect(() => {
        let urlLocation: string | null = null;
        let urlName: string | null = null;
        let urlEmail: string | null = null;

        const locKeys = ['location', 'locationId', 'location_id', 'id'];
        const nameKeys = ['name', 'userName', 'user_name', 'locationName', 'location_name'];
        const emailKeys = ['email', 'userEmail', 'user_email'];

        const searchParams = new URLSearchParams(window.location.search);
        const hashParams = window.location.hash.includes('?') 
            ? new URLSearchParams(window.location.hash.split('?')[1]) 
            : null;

        const getParam = (key: string) => (searchParams.get(key) || hashParams?.get(key)) || null;

        for (const k of locKeys) { const val = getParam(k); if (val) { urlLocation = val; break; } }
        for (const k of nameKeys) { const val = getParam(k); if (val) { urlName = val; break; } }
        for (const k of emailKeys) { const val = getParam(k); if (val) { urlEmail = val; break; } }

        if (urlLocation) {
            console.log("NOLA SMS: Detected GHL Location:", urlLocation);
        }

        const accountSettings = getAccountSettings();
        let changed = false;

        const newSettings = { ...accountSettings };

        if (urlLocation && urlLocation !== accountSettings.ghlLocationId) {
            newSettings.ghlLocationId = urlLocation;
            setLocationId(urlLocation);
            changed = true;
        }

        if (urlName && urlName !== accountSettings.displayName) {
            newSettings.displayName = urlName;
            changed = true;
        }

        if (urlEmail && urlEmail !== accountSettings.email) {
            newSettings.email = urlEmail;
            changed = true;
        }

        if (changed) {
            saveAccountSettings(newSettings);
        }
    }, [locationId]);

    return locationId;
}
