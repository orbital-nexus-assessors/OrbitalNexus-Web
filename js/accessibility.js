(() => {
    const storageKey = "orbital-nexus-contrast";
    const root = document.documentElement;
    const systemPreference = window.matchMedia(
        "(prefers-contrast: more)"
    );

    function readStoredPreference() {
        try {
            return localStorage.getItem(storageKey);
        } catch {
            return null;
        }
    }

    function storePreference(value) {
        try {
            localStorage.setItem(storageKey, value);
        } catch {
            /*
             * Si el navegador impide utilizar localStorage,
             * el botón seguirá funcionando durante esta página.
             */
        }
    }

    function updateButtons(highContrastEnabled) {
        const buttons = document.querySelectorAll(
            ".contrast-toggle"
        );

        buttons.forEach((button) => {
            button.setAttribute(
                "aria-pressed",
                String(highContrastEnabled)
            );
        });
    }

    function applyContrast(highContrastEnabled) {
        if (highContrastEnabled) {
            root.setAttribute("data-contrast", "high");
        } else {
            root.removeAttribute("data-contrast");
        }

        updateButtons(highContrastEnabled);
    }

    const storedPreference = readStoredPreference();

    const initialHighContrast =
        storedPreference === "high"
        || (
            storedPreference === null
            && systemPreference.matches
        );

    /*
     * Aplicamos la preferencia inmediatamente, antes de que
     * termine de cargarse la página.
     */
    applyContrast(initialHighContrast);

    document.addEventListener("DOMContentLoaded", () => {
        updateButtons(
            root.getAttribute("data-contrast") === "high"
        );

        document
            .querySelectorAll(".contrast-toggle")
            .forEach((button) => {
                button.addEventListener("click", () => {
                    const enableHighContrast =
                        root.getAttribute("data-contrast")
                        !== "high";

                    applyContrast(enableHighContrast);

                    storePreference(
                        enableHighContrast
                            ? "high"
                            : "normal"
                    );
                });
            });
    });

    /*
     * Si el usuario todavía no ha elegido manualmente,
     * seguimos los cambios del sistema operativo.
     */
    systemPreference.addEventListener("change", (event) => {
        if (readStoredPreference() === null) {
            applyContrast(event.matches);
        }
    });
})();