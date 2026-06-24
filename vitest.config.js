import { defineConfig } from "vitest/config";
import { fileURLToPath } from "node:url";

export default defineConfig({
    resolve: {
        alias: {
            "@": fileURLToPath(new URL("./resources/js", import.meta.url)),
        },
    },
    test: {
        environment: "node",
        include: ["resources/js/**/*.{test,spec}.{js,jsx}"],
        globals: false,
    },
});
