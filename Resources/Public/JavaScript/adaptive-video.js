/**
 * Switches <video data-adaptive-video-src> elements to their HLS stream:
 * native HLS where available (Safari), otherwise hls.js, loaded only then.
 * The original stays as <source> and is used if the stream fails.
 */
const HLS_MODULE = new URL('./Contrib/hls.js/hls.light.min.mjs', import.meta.url).href;

let hlsPromise = null;
const loadHls = () => {
  hlsPromise ??= import(HLS_MODULE).then((module) => module.default);
  return hlsPromise;
};

/**
 * Applies what the renderer deferred, so the original was not loaded first.
 */
const applyDeferredAttributes = (video) => {
  const { adaptiveVideoPreload: preload, adaptiveVideoAutoplay: autoplay } = video.dataset;
  if (preload) {
    video.preload = preload;
  }
  if (autoplay) {
    video.autoplay = true;
    video.play().catch(() => {});
  }
};

const useOriginal = (video) => {
  video.removeAttribute('src');
  video.load();
  applyDeferredAttributes(video);
};

const useNativeHls = (video, source) => {
  video.addEventListener('error', () => useOriginal(video), { once: true });
  video.src = source;
  applyDeferredAttributes(video);
};

const useHlsJs = (Hls, video, source) => {
  const { adaptiveVideoPreload: preload, adaptiveVideoAutoplay: autoplay, adaptiveVideoCapLevel: capLevel } = video.dataset;
  // Segments are only loaded right away if the video would load anyway
  const startLoad = preload === 'auto' || Boolean(autoplay);
  const hls = new Hls({
    autoStartLoad: startLoad,
    capLevelToPlayerSize: capLevel === '1',
  });
  if (!startLoad) {
    video.addEventListener('play', () => hls.startLoad(), { once: true });
  }
  hls.on(Hls.Events.ERROR, (event, data) => {
    if (data.fatal) {
      hls.destroy();
      useOriginal(video);
    }
  });
  hls.loadSource(source);
  hls.attachMedia(video);
  applyDeferredAttributes(video);
};

const initialize = async (video) => {
  const source = video.dataset.adaptiveVideoSrc;
  if (video.canPlayType('application/vnd.apple.mpegurl') !== '') {
    useNativeHls(video, source);
    return;
  }

  try {
    const Hls = await loadHls();
    if (!Hls.isSupported()) {
      useOriginal(video);
      return;
    }
    useHlsJs(Hls, video, source);
  } catch (error) {
    useOriginal(video);
  }
};

document.querySelectorAll('video[data-adaptive-video-src]').forEach(initialize);
