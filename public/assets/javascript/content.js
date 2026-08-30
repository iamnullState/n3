document.addEventListener("input", (event) => {
    if (!(event.target instanceof HTMLInputElement) || event.target.id !== "page-title") return;
    const slug = document.getElementById("page-slug");
    if (!(slug instanceof HTMLInputElement) || slug.dataset.slugAutofill !== "true" || slug.dataset.edited === "true") return;
    slug.value = event.target.value.toLowerCase().trim().replace(/[^a-z0-9]+/g, "-").replace(/^-|-$/g, "");
});

document.addEventListener("input", (event) => {
    if (event.target instanceof HTMLInputElement && event.target.id === "page-slug") event.target.dataset.edited = "true";
});
