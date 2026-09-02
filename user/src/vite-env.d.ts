/// <reference types="vite/client" />

interface ImportMetaEnv {
    readonly VITE_API_BASE: string
    readonly VITE_API_SEND_SMS: string
    readonly VITE_API_SMS_CONTAINER: string
}

interface ImportMeta {
    readonly env: ImportMetaEnv
}
