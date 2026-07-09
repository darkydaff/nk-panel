import tailwindcss from "@tailwindcss/vite";
import { defineConfig } from "vite";
import path from "path";

export default defineConfig({
  plugins: [tailwindcss()],
  build: {
    // No JS bundle – CSS only
    rollupOptions: {
      input: path.resolve(__dirname, "src/css/app.css"),
      output: {
        assetFileNames: "css/[name][extname]",
      },
    },
    // Write directly to public/ so PHP can serve it
    outDir: "public",
    emptyOutDir: false,
  },
});
