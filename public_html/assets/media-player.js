'use strict';

document.addEventListener('DOMContentLoaded', () => {
    const player = document.getElementById('playlist-player');
    const tracks = [...document.querySelectorAll('.playlist-track')];
    if (!player || tracks.length === 0) return;

    let current = 0;

    function select(index, autoplay) {
        current = index;
        player.src = tracks[current].dataset.src;
        tracks.forEach((track, number) => {
            track.setAttribute('aria-current', number === current ? 'true' : 'false');
        });
        if (autoplay) player.play().catch(() => {});
    }

    tracks.forEach((track, index) => {
        track.addEventListener('click', () => select(index, true));
    });

    player.addEventListener('ended', () => {
        if (current + 1 < tracks.length) select(current + 1, true);
    });

    select(0, false);
});
