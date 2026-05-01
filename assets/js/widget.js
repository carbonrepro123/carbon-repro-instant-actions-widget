(function () {
  'use strict';

  var settings = window.carbonReproWidget || {};
  var smsNumber = settings.smsNumber || '';
  var ajaxUrl = settings.ajaxUrl || '';
  var nonce = settings.nonce || '';
  var chatNonce = settings.chatNonce || '';
  var startNonce = settings.startNonce || '';
  var uploadNonce = settings.uploadNonce || '';
  var catalogNonce = settings.catalogNonce || '';
  var chatEnabled = settings.chatEnabled === '1';
  var welcomeMessage = settings.welcomeMessage || 'Hi, how can I help?';
  var strings = settings.strings || {};
  var pendingWidgetSubmission = false;
  var chatConversationId = null;
  var chatInitialized = false;
  var chatMessageCount = 0;
  var chatPollTimer = null;
  var toastTimer = null;
  var chatRequestInFlight = false;
  var pendingUploads = [];
  var SESSION_KEY = 'criaw_chat_session_v1';
  var ARCHIVE_KEY = 'criaw_chat_archives_v1';
  var CHAT_SESSION_TTL_MS = 3 * 60 * 60 * 1000;

  document.documentElement.setAttribute('data-widget-hidden', 'true');

  // Fail-safe click delegation: if a runtime error prevents DOMContentLoaded
  // bindings from running, the launcher must still open.
  document.addEventListener('click', function (event) {
    var target = event && event.target;
    if (!target || !target.closest) {
      return;
    }

    if (target.closest('#watchBtn') || target.closest('#watchLabel')) {
      toggleWidget(event);
      return;
    }

    if (target.closest('#watchMenuCloseBtn') || target.closest('#watchChatCloseBtn') || target.closest('#watchFormCloseBtn')) {
      closeWidget(event);
      return;
    }
  }, true);

  function getElements() {
    return {
      widget: document.getElementById('watchWidget'),
      launcherButton: document.getElementById('watchBtn'),
      launcherLabel: document.getElementById('watchLabel'),
      unreadBadge: document.getElementById('watchUnreadBadge'),
      menu: document.getElementById('watchMenu'),
      form: document.getElementById('watchFormPopup'),
      chat: document.getElementById('watchChatPopup'),
      overlay: document.getElementById('watchOverlay'),
      menuClose: document.getElementById('watchMenuCloseBtn'),
      formBack: document.getElementById('watchFormBackBtn'),
      formClose: document.getElementById('watchFormCloseBtn'),
      chatBack: document.getElementById('watchChatBackBtn'),
      chatClose: document.getElementById('watchChatCloseBtn'),
      chatMessages: document.getElementById('watchChatMessages'),
      chatIntake: document.getElementById('watchChatIntake'),
      chatStart: document.getElementById('watchChatStartBtn'),
      chatLeadName: document.getElementById('watchChatLeadName'),
      chatLeadEmail: document.getElementById('watchChatLeadEmail'),
      chatLeadPhone: document.getElementById('watchChatLeadPhone'),
      chatLeadNeed: document.getElementById('watchChatLeadNeed'),
      chatHistory: document.getElementById('watchChatHistory'),
      chatHistoryList: document.getElementById('watchChatHistoryList'),
      chatHistoryClose: document.getElementById('watchChatHistoryClose'),
      chatForm: document.getElementById('watchChatForm'),
      chatConsent: document.getElementById('watchChatConsent'),
      chatSmsConsent: document.getElementById('watchChatSmsConsent'),
      chatWhatsappConsent: document.getElementById('watchChatWhatsappConsent'),
      chatUpload: document.getElementById('watchChatUpload'),
      chatAttachments: document.getElementById('watchChatAttachments'),
      chatUploadStatus: document.getElementById('watchChatUploadStatus'),
      chatInput: document.getElementById('watchChatInput'),
      chatSend: document.getElementById('watchChatSend'),
      chatPreviousBtn: document.getElementById('watchChatPreviousBtn'),
      chatResetBtn: document.getElementById('watchChatResetBtn'),
      toast: document.getElementById('watchToast')
    };
  }

  function loadSessionData(key, fallback) {
    try {
      var raw = window.localStorage.getItem(key);
      return raw ? JSON.parse(raw) : fallback;
    } catch (error) {
      return fallback;
    }
  }

  function saveSessionData(key, value) {
    try {
      window.localStorage.setItem(key, JSON.stringify(value));
    } catch (error) {}
  }

  function getCurrentChatSession() {
    var session = loadSessionData(SESSION_KEY, {
      conversationId: null,
      messages: [],
      lead: null,
      updatedAt: 0
    });
    if (session && session.updatedAt && (Date.now() - session.updatedAt) > CHAT_SESSION_TTL_MS) {
      if (session.messages && session.messages.length) {
        upsertArchivedConversation(session);
      }
      session = {
        conversationId: null,
        messages: [],
        lead: session.lead || null,
        updatedAt: Date.now()
      };
      saveSessionData(SESSION_KEY, session);
    }
    return session;
  }

  function setCurrentChatSession(session) {
    session.updatedAt = Date.now();
    saveSessionData(SESSION_KEY, session);
  }

  function getArchivedChats() {
    return loadSessionData(ARCHIVE_KEY, []);
  }

  function setArchivedChats(chats) {
    saveSessionData(ARCHIVE_KEY, chats);
  }

  function getStoredLead() {
    var session = getCurrentChatSession();
    return session && session.lead ? session.lead : null;
  }

  function setStoredLead(lead) {
    var session = getCurrentChatSession();
    session.lead = lead || null;
    setCurrentChatSession(session);
  }

  function setCookie(name, value, maxAgeSeconds) {
    var cookie = name + '=' + encodeURIComponent(value) + '; path=/; SameSite=Lax';
    if (typeof maxAgeSeconds === 'number') {
      cookie += '; max-age=' + maxAgeSeconds;
    }
    document.cookie = cookie;
  }

  function deleteCookie(name) {
    document.cookie = name + '=; path=/; expires=Thu, 01 Jan 1970 00:00:00 GMT; SameSite=Lax';
  }

  function setTrackingCookies(extraData) {
    var context = getTrackingContext();
    var payload = {};

    Object.keys(context).forEach(function (key) {
      payload[key] = context[key];
    });

    if (extraData) {
      Object.keys(extraData).forEach(function (key) {
        if (extraData[key] !== undefined && extraData[key] !== null) {
          payload[key] = extraData[key];
        }
      });
    }

    setCookie('criaw_tracking_context', window.btoa(unescape(encodeURIComponent(JSON.stringify(payload)))), 3600);
  }

  function setWidgetFormCookie(isActive) {
    if (isActive) {
      setCookie('criaw_widget_form', '1', 3600);
      return;
    }

    deleteCookie('criaw_widget_form');
  }

  function getUnreadCounts() {
    return loadSessionData('criaw_chat_unread_v1', {});
  }

  function setUnreadCounts(counts) {
    saveSessionData('criaw_chat_unread_v1', counts);
  }

  function getConsentPreference() {
    return loadSessionData('criaw_chat_consent_v1', settings.consentRequired === '1');
  }

  function setConsentPreference(isChecked) {
    saveSessionData('criaw_chat_consent_v1', !!isChecked);
  }

  function getSmsConsentPreference() {
    return loadSessionData('criaw_chat_sms_consent_v1', false);
  }

  function setSmsConsentPreference(isChecked) {
    saveSessionData('criaw_chat_sms_consent_v1', !!isChecked);
  }

  function getWhatsappConsentPreference() {
    return loadSessionData('criaw_chat_whatsapp_consent_v1', false);
  }

  function setWhatsappConsentPreference(isChecked) {
    saveSessionData('criaw_chat_whatsapp_consent_v1', !!isChecked);
  }

  function showToast(message) {
    var elements = getElements();
    if (!elements.toast) {
      return;
    }

    elements.toast.textContent = message;
    elements.toast.classList.add('active');
    window.clearTimeout(toastTimer);
    toastTimer = window.setTimeout(function () {
      elements.toast.classList.remove('active');
    }, 2600);
  }

  function playNotificationSound() {
    var AudioContextCtor = window.AudioContext || window.webkitAudioContext;
    var audioContext;
    var oscillator;
    var gainNode;

    if (!AudioContextCtor) {
      return;
    }

    try {
      audioContext = new AudioContextCtor();
      oscillator = audioContext.createOscillator();
      gainNode = audioContext.createGain();
      oscillator.type = 'sine';
      oscillator.frequency.setValueAtTime(880, audioContext.currentTime);
      gainNode.gain.setValueAtTime(0.001, audioContext.currentTime);
      gainNode.gain.exponentialRampToValueAtTime(0.05, audioContext.currentTime + 0.02);
      gainNode.gain.exponentialRampToValueAtTime(0.001, audioContext.currentTime + 0.22);
      oscillator.connect(gainNode);
      gainNode.connect(audioContext.destination);
      oscillator.start();
      oscillator.stop(audioContext.currentTime + 0.22);
    } catch (error) {}
  }

  function notifyIncomingMessage(message) {
    if (!message || message.role === 'user') {
      return;
    }

    showToast((message.sender === 'human' ? (strings.humanTakeover || 'A team member has joined the chat.') : (strings.newReply || 'New reply ready')));
    playNotificationSound();
  }

  function toggleIntake(show) {
    var elements = getElements();
    if (!elements.chatIntake) {
      return;
    }

    elements.chatIntake.style.display = show ? 'flex' : 'none';
    if (elements.chatMessages) {
      elements.chatMessages.style.display = show ? 'none' : 'flex';
    }
    if (elements.chatForm) {
      elements.chatForm.style.display = show ? 'none' : 'flex';
    }
    if (elements.chatHistory) {
      elements.chatHistory.style.display = show ? 'none' : elements.chatHistory.style.display;
    }
  }

  function clearLeadInputs() {
    var elements = getElements();
    if (elements.chatLeadName) { elements.chatLeadName.value = ''; }
    if (elements.chatLeadEmail) { elements.chatLeadEmail.value = ''; }
    if (elements.chatLeadPhone) { elements.chatLeadPhone.value = ''; }
    if (elements.chatLeadNeed) { elements.chatLeadNeed.value = ''; }
    if (elements.chatConsent) { elements.chatConsent.checked = false; }
    if (elements.chatSmsConsent) { elements.chatSmsConsent.checked = false; }
    if (elements.chatWhatsappConsent) { elements.chatWhatsappConsent.checked = false; }
    setConsentPreference(false);
    setSmsConsentPreference(false);
    setWhatsappConsentPreference(false);
  }

  function populateLeadInputs() {
    var elements = getElements();
    var lead = getStoredLead();
    if (!lead) {
      return;
    }

    if (elements.chatLeadName) { elements.chatLeadName.value = lead.name || ''; }
    if (elements.chatLeadEmail) { elements.chatLeadEmail.value = lead.email || ''; }
    if (elements.chatLeadPhone) { elements.chatLeadPhone.value = lead.phone || ''; }
    if (elements.chatLeadNeed) { elements.chatLeadNeed.value = lead.looking_for || ''; }
  }

  function collectLeadInputs() {
    var elements = getElements();
    return {
      name: elements.chatLeadName ? elements.chatLeadName.value.trim() : '',
      email: elements.chatLeadEmail ? elements.chatLeadEmail.value.trim() : '',
      phone: elements.chatLeadPhone ? elements.chatLeadPhone.value.trim() : '',
      looking_for: elements.chatLeadNeed ? elements.chatLeadNeed.value.trim() : ''
    };
  }

  function findUrls(text) {
    var matches = String(text || '').match(/https?:\/\/[^\s<>"']+/g);
    return (matches || []).map(function (url) {
      return url.replace(/[)\].,!?:;]+$/g, '');
    });
  }

  function cleanCatalogMessageText(text) {
    var cleaned = String(text || '');

    cleaned = cleaned.replace(/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/gi, function (_, label) {
      var normalized = String(label || '').trim().toLowerCase();
      if (normalized === 'here' || normalized === 'view here' || normalized === 'link' || normalized === 'product link') {
        return 'below';
      }
      return label;
    });

    cleaned = cleaned.replace(/https?:\/\/[^\s<>"']+/gi, '');
    cleaned = cleaned.replace(/\(\s*\)/g, '');
    cleaned = cleaned.replace(/\[\s*\]/g, '');
    cleaned = cleaned.replace(/\s+([,.!?])/g, '$1');
    cleaned = cleaned.replace(/\s{2,}/g, ' ');
    cleaned = cleaned.replace(/check it out below/gi, 'check it out below');

    return cleaned.trim();
  }

  function normalizeMessageMeta(meta) {
    if (!meta) {
      return null;
    }

    return {
      catalogCards: Array.isArray(meta.catalogCards) ? meta.catalogCards : (Array.isArray(meta.catalog_cards) ? meta.catalog_cards : []),
      catalogLinks: Array.isArray(meta.catalogLinks) ? meta.catalogLinks : (Array.isArray(meta.catalog_links) ? meta.catalog_links : []),
      contactActions: Array.isArray(meta.contactActions) ? meta.contactActions : (Array.isArray(meta.contact_actions) ? meta.contact_actions : [])
    };
  }

  function scrollChatToBottom(force) {
    var elements = getElements();
    var container = elements.chatMessages;
    var isNearBottom;
    if (!container) {
      return;
    }

    isNearBottom = (container.scrollHeight - (container.scrollTop + container.clientHeight)) < 140;
    if (force || isNearBottom) {
      container.scrollTop = container.scrollHeight;
    }
  }

  function renderPendingUploads() {
    var elements = getElements();
    if (!elements.chatAttachments) {
      return;
    }

    elements.chatAttachments.innerHTML = '';
    if (!pendingUploads.length) {
      elements.chatAttachments.classList.remove('active');
      return;
    }

    elements.chatAttachments.classList.add('active');
    pendingUploads.forEach(function (item) {
      var chip = document.createElement('div');
      chip.className = 'watch-chat-attachment';
      chip.innerHTML =
        '<img class="watch-chat-attachment-thumb" src="' + (item.url || '') + '" alt="">' +
        '<span class="watch-chat-attachment-name">' + (item.name || 'Photo') + '</span>' +
        '<button type="button" class="watch-chat-attachment-remove" aria-label="Remove attachment">' +
          '<svg viewBox="0 0 24 24" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>' +
        '</button>';
      var removeBtn = chip.querySelector('button');
      if (removeBtn) {
        removeBtn.addEventListener('click', function () {
          pendingUploads = pendingUploads.filter(function (u) { return u !== item; });
          renderPendingUploads();
          if (elements.chatUploadStatus) {
            elements.chatUploadStatus.textContent = pendingUploads.length ? 'Photo attached.' : '';
          }
        });
      }
      elements.chatAttachments.appendChild(chip);
    });
  }

  function appendUserMedia(row, uploads) {
    if (!row || !uploads || !uploads.length) {
      return;
    }

    var media = document.createElement('div');
    media.className = 'watch-chat-user-media';
    uploads.slice(0, 4).forEach(function (item) {
      var link = document.createElement('a');
      link.href = item.url;
      link.target = '_blank';
      link.rel = 'noopener noreferrer';
      link.innerHTML = '<img src="' + item.url + '" alt="">';
      media.appendChild(link);
    });
    row.appendChild(media);
  }

  function renderCatalogCards(target, cards, options) {
    if (!target || !Array.isArray(cards) || !cards.length) {
      return;
    }

    var shell = document.createElement('div');
    var wrap = document.createElement('div');
    shell.className = 'watch-chat-carousel';
    wrap.className = 'watch-chat-cards' + (cards.length > 1 ? ' watch-chat-cards-grid' : '');
    if (options && options.compact) {
      wrap.className += ' watch-chat-cards-compact';
    }

    cards.forEach(function (card) {
      var link = document.createElement('a');
      link.className = 'watch-chat-card';
      link.href = card.url || '#';
      link.target = '_blank';
      link.rel = 'noopener noreferrer';
      link.innerHTML =
        '<span class="watch-chat-card-image">' + (card.image ? '<img src="' + card.image + '" alt="">' : '<span class="watch-chat-card-placeholder"></span>') + '</span>' +
        '<span class="watch-chat-card-body">' +
          '<strong>' + (card.title || '') + '</strong>' +
          (card.description ? '<span class="watch-chat-card-description">' + card.description + '</span>' : '') +
          '<span class="watch-chat-card-meta">' +
            (card.category ? '<span class="watch-chat-card-category">' + card.category + '</span>' : '<span class="watch-chat-card-category">&nbsp;</span>') +
            (card.price ? '<em>' + card.price + '</em>' : '<em>&nbsp;</em>') +
          '</span>' +
          '<span class="watch-chat-card-cta">' + (card.type === 'category' ? 'View Category' : 'View Product') + '</span>' +
        '</span>';
      wrap.appendChild(link);
    });
    shell.appendChild(wrap);
    target.appendChild(shell);
    scrollChatToBottom(true);
  }

  function renderCatalogLinks(target, links) {
    if (!target || !Array.isArray(links) || !links.length) {
      return;
    }

    var wrap = document.createElement('div');
    wrap.className = 'watch-chat-links';
    links.forEach(function (linkData) {
      var link = document.createElement('a');
      link.className = 'watch-chat-link-btn';
      link.href = linkData.url || '#';
      link.target = '_blank';
      link.rel = 'noopener noreferrer';
      link.textContent = linkData.label || 'View More';
      wrap.appendChild(link);
    });
    target.appendChild(wrap);
    scrollChatToBottom(true);
  }

  function renderContactActions(target, actions) {
    if (!target || !Array.isArray(actions) || !actions.length) {
      return;
    }

    var wrap = document.createElement('div');
    wrap.className = 'watch-chat-contact-actions';
    actions.forEach(function (action) {
      var link = document.createElement('a');
      link.className = 'watch-chat-contact-btn watch-chat-contact-btn-' + (action.type || 'contact');
      link.href = action.url || '#';
      link.target = action.type === 'email' || action.type === 'call' ? '_self' : '_blank';
      link.rel = 'noopener noreferrer';
      link.textContent = action.label || 'Contact';
      link.addEventListener('click', function () {
        trackEvent('contact_click', { cta: action.type || 'contact' });
      });
      wrap.appendChild(link);
    });
    target.appendChild(wrap);
    scrollChatToBottom(true);
  }

  function hydrateCatalogCardsForBubble(bubble, text) {
    var urls = findUrls(text);
    var payload;
    if (!bubble || !urls.length || !catalogNonce) {
      return;
    }

    payload = new window.FormData();
    payload.append('action', 'criaw_catalog_cards');
    payload.append('nonce', catalogNonce);
    urls.forEach(function (url) {
      payload.append('urls[]', url);
    });

    postFormData(payload).then(function (data) {
      if (!data || !data.success || !data.data || !data.data.cards) {
        return;
      }
      var textNode = bubble.querySelector('.watch-chat-message-text');
      var contentNode = bubble.querySelector('.watch-chat-rich') || bubble;
      if (textNode) {
        textNode.textContent = cleanCatalogMessageText(text);
      }
      renderCatalogCards(contentNode, data.data.cards, {});
    }).catch(function () {});
  }

  function updateUnreadBadge() {
    var elements = getElements();
    if (!elements.unreadBadge) {
      return;
    }

    var counts = getUnreadCounts();
    var total = Object.keys(counts).reduce(function (sum, key) {
      return sum + (parseInt(counts[key], 10) || 0);
    }, 0);

    elements.unreadBadge.textContent = String(total);
    elements.unreadBadge.classList.toggle('active', total > 0);
    elements.unreadBadge.setAttribute('aria-hidden', total > 0 ? 'false' : 'true');
  }

  function markConversationRead(conversationId) {
    if (!conversationId) {
      updateUnreadBadge();
      return;
    }

    var counts = getUnreadCounts();
    delete counts[String(conversationId)];
    setUnreadCounts(counts);
    updateUnreadBadge();
  }

  function incrementUnreadCount(conversationId, amount) {
    if (!conversationId || !amount) {
      return;
    }

    var counts = getUnreadCounts();
    var key = String(conversationId);
    counts[key] = (parseInt(counts[key], 10) || 0) + amount;
    setUnreadCounts(counts);
    updateUnreadBadge();
  }

  function upsertArchivedConversation(conversation) {
    if (!conversation || !conversation.messages || !conversation.messages.length) {
      return;
    }

    var archives = getArchivedChats();
    var conversationId = conversation.conversationId || null;
    var archiveItem = {
      conversationId: conversationId,
      title: conversation.title || new Date().toLocaleString(),
      preview: conversation.preview || (conversation.messages[0] ? conversation.messages[0].content.slice(0, 80) : ''),
      messages: conversation.messages,
      updatedAt: conversation.updatedAt || Date.now()
    };
    var updated = false;

    archives = archives.map(function (item) {
      if (conversationId && item.conversationId && String(item.conversationId) === String(conversationId)) {
        updated = true;
        return archiveItem;
      }
      return item;
    });

    if (!updated) {
      archives.push(archiveItem);
    }

    setArchivedChats(archives);
  }

  function isChatVisible() {
    var elements = getElements();
    return !!(elements.chat && elements.chat.classList.contains('active'));
  }

  function getKnownConversationStates() {
    var known = {};
    var current = getCurrentChatSession();
    var archives = getArchivedChats();

    if (current.conversationId) {
      known[String(current.conversationId)] = {
        conversationId: current.conversationId,
        messages: Array.isArray(current.messages) ? current.messages : [],
        isCurrent: true
      };
    }

    archives.forEach(function (archive) {
      if (!archive || !archive.conversationId) {
        return;
      }

      if (known[String(archive.conversationId)] && known[String(archive.conversationId)].messages.length >= (Array.isArray(archive.messages) ? archive.messages.length : 0)) {
        return;
      }

      known[String(archive.conversationId)] = {
        conversationId: archive.conversationId,
        messages: Array.isArray(archive.messages) ? archive.messages : [],
        isCurrent: !!known[String(archive.conversationId)]
      };
    });

    return Object.keys(known).map(function (key) {
      return known[key];
    });
  }

  function syncConversationMessages(conversationId, messages) {
    if (!conversationId || !Array.isArray(messages)) {
      return;
    }

    var current = getCurrentChatSession();
    if (current.conversationId && String(current.conversationId) === String(conversationId)) {
      setCurrentChatSession({
        conversationId: conversationId,
        messages: messages,
        lead: getStoredLead()
      });
      chatMessageCount = messages.length;
    }

    upsertArchivedConversation({
      conversationId: conversationId,
      messages: messages
    });
  }

  function normalizeMessages(messages) {
    if (!Array.isArray(messages)) {
      return [];
    }

    return messages.map(function (message) {
      return {
        role: message && message.role === 'assistant' ? 'assistant' : 'user',
        content: message && message.content ? message.content : '',
        time: message && message.time ? message.time : '',
        sender: message && message.sender === 'human' ? 'human' : 'bot',
        meta: normalizeMessageMeta(message && message.meta ? message.meta : null)
      };
    });
  }

  function syncCurrentSessionFromDom() {
    var elements = getElements();
    if (!elements.chatMessages) {
      return;
    }

    var messages = Array.prototype.map.call(elements.chatMessages.querySelectorAll('.watch-chat-message-row'), function (node) {
      var meta = null;
      try {
        meta = node.dataset && node.dataset.messageMeta ? JSON.parse(node.dataset.messageMeta) : null;
      } catch (error) {
        meta = null;
      }
      return {
        role: node.classList.contains('watch-chat-message-row-user') ? 'user' : 'assistant',
        content: (node.querySelector('.watch-chat-message-text') ? node.querySelector('.watch-chat-message-text').textContent : '') || '',
        time: '',
        sender: node.querySelector && node.querySelector('.watch-chat-human') ? 'human' : 'bot',
        meta: meta
      };
    });

    setCurrentChatSession({
      conversationId: chatConversationId,
      messages: messages,
      lead: getStoredLead()
    });
    chatMessageCount = messages.length;
    markConversationRead(chatConversationId);
  }

  function renderArchivedChats() {
    var elements = getElements();
    if (!elements.chatHistoryList) {
      return;
    }

    var archives = getArchivedChats();
    elements.chatHistoryList.innerHTML = '';

    if (!archives.length) {
      var empty = document.createElement('div');
      empty.className = 'watch-chat-history-empty';
      empty.textContent = (strings && strings.emptyHistory) || 'No previous chats saved on this browser yet.';
      elements.chatHistoryList.appendChild(empty);
      return;
    }

    archives.slice().reverse().forEach(function (archive) {
      var wrapper = document.createElement('div');
      var item = document.createElement('button');
      item.type = 'button';
      item.className = 'watch-chat-history-item';
      item.innerHTML = '<strong>' + (archive.title || 'Previous chat') + '</strong><span>' + (archive.preview || '') + '</span>';
      item.addEventListener('click', function () {
        var elements = getElements();
        var restoredMessages = Array.isArray(archive.messages) ? archive.messages : [];

        chatConversationId = archive.conversationId || null;
        chatInitialized = false;
        chatMessageCount = 0;

        setCurrentChatSession({
          conversationId: chatConversationId,
          messages: restoredMessages
        });

        if (elements.chatMessages) {
          elements.chatMessages.innerHTML = '';
          restoredMessages.forEach(function (message) {
            if (message.role === 'assistant') {
              addAssistantReply(message.content || '', message.sender === 'human' ? 'watch-chat-human' : '', message.meta || null);
            } else {
              addChatMessage('user', message.content || '', '', message.meta || null);
            }
          });
        }

        chatInitialized = true;
        chatMessageCount = restoredMessages.length;
        markConversationRead(chatConversationId);
        toggleHistoryPanel(false);
        startChatPolling();
      });
      wrapper.appendChild(item);
      elements.chatHistoryList.appendChild(wrapper);
    });
  }

  function toggleHistoryPanel(show) {
    var elements = getElements();
    if (!elements.chatHistory) {
      return;
    }

    if (show) {
      renderArchivedChats();
      elements.chatHistory.classList.add('active');
      elements.chatHistory.setAttribute('aria-hidden', 'false');
    } else {
      elements.chatHistory.classList.remove('active');
      elements.chatHistory.setAttribute('aria-hidden', 'true');
    }
  }

  function resetCurrentChat() {
    var current = getCurrentChatSession();
    if (current.messages && current.messages.length) {
      upsertArchivedConversation({
        conversationId: current.conversationId || chatConversationId,
        title: new Date().toLocaleString(),
        preview: current.messages[0] ? current.messages[0].content.slice(0, 80) : '',
        messages: current.messages
      });
    }

    chatConversationId = null;
    chatInitialized = false;
    chatMessageCount = 0;
    setStoredLead(null);
    clearLeadInputs();
    setCurrentChatSession({ conversationId: null, messages: [], lead: null });
    markConversationRead(null);

    var elements = getElements();
    if (elements.chatMessages) {
      elements.chatMessages.innerHTML = '';
    }

    initializeChat();
    toggleHistoryPanel(false);
    startChatPolling();
  }

  function getVisitorId() {
    var key = 'criaw_visitor_id';
    var existing = '';

    try {
      existing = window.localStorage.getItem(key) || '';
      if (!existing && window.crypto && typeof window.crypto.randomUUID === 'function') {
        existing = window.crypto.randomUUID();
        window.localStorage.setItem(key, existing);
      } else if (!existing) {
        existing = 'visitor-' + Date.now() + '-' + Math.random().toString(16).slice(2);
        window.localStorage.setItem(key, existing);
      }
    } catch (error) {
      existing = 'visitor-' + Date.now();
    }

    return existing;
  }

  function getDeviceType() {
    var width = window.innerWidth || 0;
    if (width <= 767) {
      return 'Mobile';
    }

    if (width <= 1024) {
      return 'Tablet';
    }

    return 'Desktop';
  }

  function getBrowser() {
    var ua = navigator.userAgent;
    if (/Edg\//.test(ua)) { return 'Edge'; }
    if (/Chrome\//.test(ua) && !/Edg\//.test(ua)) { return 'Chrome'; }
    if (/Safari\//.test(ua) && !/Chrome\//.test(ua)) { return 'Safari'; }
    if (/Firefox\//.test(ua)) { return 'Firefox'; }
    return 'Other';
  }

  function getOS() {
    var ua = navigator.userAgent;
    if (/Windows/.test(ua)) { return 'Windows'; }
    if (/Mac OS X/.test(ua)) { return 'macOS'; }
    if (/Android/.test(ua)) { return 'Android'; }
    if (/iPhone|iPad|iPod/.test(ua)) { return 'iOS'; }
    if (/Linux/.test(ua)) { return 'Linux'; }
    return 'Other';
  }

  function getReferrerDomain(referrer) {
    if (!referrer) {
      return '';
    }

    try {
      return new window.URL(referrer).hostname || '';
    } catch (error) {
      return '';
    }
  }

  function getQueryParam(name) {
    try {
      return new window.URLSearchParams(window.location.search).get(name) || '';
    } catch (error) {
      return '';
    }
  }

  function getTrackingContext() {
    return {
      visitor_id: getVisitorId(),
      page_url: window.location.href,
      page_path: window.location.pathname,
      page_title: document.title || '',
      referrer_url: document.referrer || '',
      referrer_domain: getReferrerDomain(document.referrer || ''),
      utm_source: getQueryParam('utm_source'),
      utm_medium: getQueryParam('utm_medium'),
      utm_campaign: getQueryParam('utm_campaign'),
      utm_term: getQueryParam('utm_term'),
      utm_content: getQueryParam('utm_content'),
      device_type: getDeviceType(),
      browser: getBrowser(),
      os: getOS(),
      language: navigator.language || '',
      timezone: (window.Intl && window.Intl.DateTimeFormat) ? window.Intl.DateTimeFormat().resolvedOptions().timeZone || '' : '',
      screen_size: (window.screen ? window.screen.width + 'x' + window.screen.height : ''),
      user_agent: navigator.userAgent || ''
    };
  }

  function postFormData(formData) {
    if (!ajaxUrl) {
      return Promise.reject(new Error('Missing ajaxUrl'));
    }

    return window.fetch(ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      body: formData
    }).then(function (response) {
      return response.json();
    });
  }

  function trackEvent(eventName, extraData) {
    if (!ajaxUrl || !nonce || !eventName) {
      return;
    }

    var payload = new window.FormData();
    var context = getTrackingContext();
    setTrackingCookies(extraData || {});

    Object.keys(context).forEach(function (key) {
      payload.append(key, context[key]);
    });

    if (extraData) {
      Object.keys(extraData).forEach(function (key) {
        if (extraData[key] !== undefined && extraData[key] !== null) {
          payload.append(key, extraData[key]);
        }
      });
    }

    payload.append('action', 'criaw_track_event');
    payload.append('nonce', nonce);
    payload.append('event', eventName);

    postFormData(payload).catch(function () {});
  }

  function setExpandedState(elements) {
    ['menu', 'form', 'chat'].forEach(function (key) {
      if (elements[key]) {
        elements[key].setAttribute('aria-hidden', elements[key].classList.contains('active') ? 'false' : 'true');
      }
    });
  }

  function hideAllPanels(elements) {
    ['menu', 'form', 'chat'].forEach(function (key) {
      if (elements[key]) {
        elements[key].classList.remove('active');
      }
    });
  }

  function closeWidget(event) {
    if (event) {
      event.preventDefault();
      event.stopPropagation();
    }

    var elements = getElements();
    if (!elements.overlay) {
      return;
    }

    hideAllPanels(elements);
    elements.overlay.classList.remove('active');
    setExpandedState(elements);
    toggleHistoryPanel(false);
    setWidgetFormCookie(false);

    window.setTimeout(function () {
      document.documentElement.setAttribute('data-widget-hidden', 'true');
    }, 320);
  }

  function openMenu() {
    var elements = getElements();
    if (!elements.menu || !elements.overlay) {
      return;
    }

    document.documentElement.removeAttribute('data-widget-hidden');
    hideAllPanels(elements);
    elements.menu.classList.add('active');
    elements.overlay.classList.add('active');
    setExpandedState(elements);
    trackEvent('widget_open', { cta: 'launcher' });
  }

  function toggleWidget(event) {
    if (event) {
      event.preventDefault();
      event.stopPropagation();
    }

    var elements = getElements();
    if (!elements.menu) {
      return;
    }

    if (elements.menu.classList.contains('active')) {
      closeWidget(event);
      return;
    }

    openMenu();
  }

  function showForm(event) {
    if (event) {
      event.preventDefault();
      event.stopPropagation();
    }

    var elements = getElements();
    if (!elements.form || !elements.overlay) {
      return;
    }

    document.documentElement.removeAttribute('data-widget-hidden');
    hideAllPanels(elements);
    elements.form.classList.add('active');
    elements.overlay.classList.add('active');
    setExpandedState(elements);
    setTrackingCookies({ cta: 'form' });
    setWidgetFormCookie(true);
    trackEvent('show_form', { cta: 'form' });
  }

  function hideForm(event) {
    if (event) {
      event.preventDefault();
      event.stopPropagation();
    }

    var elements = getElements();
    hideAllPanels(elements);
    if (elements.menu) {
      elements.menu.classList.add('active');
      setExpandedState(elements);
    }
  }

  function addChatMessage(role, text, extraClass, meta) {
    var elements = getElements();
    meta = normalizeMessageMeta(meta);
    if (!elements.chatMessages) {
      return null;
    }

    var row = document.createElement('div');
    var bubble = document.createElement('div');
    var textNode = document.createElement('div');
    var richNode = null;

    row.className = 'watch-chat-message-row watch-chat-message-row-' + role;
    bubble.className = 'watch-chat-bubble watch-chat-bubble-' + role + (extraClass ? ' ' + extraClass : '');
    textNode.className = 'watch-chat-message-text';
    textNode.textContent = text || '';
    bubble.appendChild(textNode);
    row.appendChild(bubble);

    if (role === 'assistant') {
      richNode = document.createElement('div');
      richNode.className = 'watch-chat-rich';
      row.appendChild(richNode);
    }

    if (meta) {
      row.dataset.messageMeta = JSON.stringify(meta);
    }

    elements.chatMessages.appendChild(row);

    if (role === 'assistant' && meta && (meta.catalogCards || meta.catalogLinks || meta.contactActions)) {
      if (meta.catalogCards && meta.catalogCards.length) {
        renderCatalogCards(richNode, meta.catalogCards, { compact: meta.catalogCards.length === 1 });
      }
      if (meta.catalogLinks && meta.catalogLinks.length) {
        renderCatalogLinks(richNode, meta.catalogLinks);
      }
      if (meta.contactActions && meta.contactActions.length) {
        renderContactActions(richNode, meta.contactActions);
      }
    } else if (role === 'assistant') {
      hydrateCatalogCardsForBubble(row, text);
    }

    scrollChatToBottom(true);
    syncCurrentSessionFromDom();
    return row;
  }

  function splitAssistantReply(text) {
    var cleaned = String(text || '').replace(/\r\n/g, '\n').trim();
    if (!cleaned) {
      return [];
    }

    var paragraphs = cleaned
      .split(/\n{2,}/g)
      .map(function (p) { return p.trim(); })
      .filter(Boolean);

    var chunks = [];
    paragraphs.forEach(function (p) {
      if (p.length <= 360) {
        chunks.push(p);
        return;
      }

      var sentences = String(p).match(/[^.!?]+[.!?]+(?:\s+|$)|[^.!?]+$/g) || [p];
      var buffer = '';
      sentences.forEach(function (s) {
        s = String(s || '').trim();
        if (!s) {
          return;
        }
        if (!buffer) {
          buffer = s;
          return;
        }
        if ((buffer + ' ' + s).length <= 340) {
          buffer += ' ' + s;
          return;
        }
        chunks.push(buffer);
        buffer = s;
      });
      if (buffer) {
        chunks.push(buffer);
      }
    });

    return chunks.slice(0, 10);
  }

  function addAssistantReply(text, extraClass, meta) {
    var parts = splitAssistantReply(text);
    if (!parts.length) {
      addChatMessage('assistant', '', extraClass, meta);
      return;
    }

    parts.forEach(function (part, index) {
      addChatMessage('assistant', part, extraClass, index === parts.length - 1 ? meta : null);
    });
  }

  function initializeChat() {
    if (!chatEnabled) {
      return;
    }

    var session = getCurrentChatSession();
    chatConversationId = session.conversationId || null;
    populateLeadInputs();

    if (session.messages && session.messages.length) {
      toggleIntake(false);
      var elements = getElements();
      if (elements.chatMessages && !elements.chatMessages.children.length) {
        session.messages.forEach(function (message) {
          if (message.role === 'assistant') {
            addAssistantReply(message.content || '', message.sender === 'human' ? 'watch-chat-human' : '', message.meta || null);
          } else {
            addChatMessage('user', message.content || '', '', message.meta || null);
          }
        });
      }
      chatInitialized = true;
      chatMessageCount = session.messages.length;
      markConversationRead(chatConversationId);
      return;
    }

    toggleIntake(true);
  }

  function startChatPolling() {
    if (!chatNonce || chatPollTimer) {
      return;
    }

    chatPollTimer = window.setInterval(function () {
      getKnownConversationStates().forEach(function (conversation) {
        var payload = new window.FormData();
        payload.append('action', 'criaw_chat_updates');
        payload.append('nonce', chatNonce);
        payload.append('conversation_id', conversation.conversationId);
        payload.append('since', conversation.messages.length || 0);

        postFormData(payload).then(function (data) {
          var serverMessages;
          var newMessages;
          if (!data || !data.success || !data.data || !data.data.messages) {
            return;
          }

          serverMessages = normalizeMessages(data.data.messages);
          if (!serverMessages.length) {
            return;
          }

          newMessages = serverMessages;
          if (!newMessages.length) {
            return;
          }

          syncConversationMessages(conversation.conversationId, (conversation.messages || []).concat(newMessages));

          if (chatConversationId && String(chatConversationId) === String(conversation.conversationId) && isChatVisible()) {
            if (chatRequestInFlight) {
              return;
            }
            var elements = getElements();
            if (elements.chatMessages && newMessages.length) {
              newMessages.forEach(function (message) {
                if (message.role === 'assistant') {
                  addAssistantReply(message.content || '', message.sender === 'human' ? 'watch-chat-human' : '', message.meta || null);
                  notifyIncomingMessage(message);
                } else {
                  addChatMessage('user', message.content || '', '', message.meta || null);
                }
              });
            }
            markConversationRead(conversation.conversationId);
            return;
          }

          incrementUnreadCount(conversation.conversationId, newMessages.length);
          if (newMessages[0]) {
            notifyIncomingMessage(newMessages[0]);
          }
        }).catch(function () {});
      });
    }, 8000);
  }

  function stopChatPolling() {
    if (chatPollTimer) {
      window.clearInterval(chatPollTimer);
      chatPollTimer = null;
    }
  }

  function syncActiveConversationNow() {
    if (!chatConversationId || !chatNonce) {
      return Promise.resolve();
    }

    var payload = new window.FormData();
    payload.append('action', 'criaw_chat_updates');
    payload.append('nonce', chatNonce);
    payload.append('conversation_id', chatConversationId);
    payload.append('since', chatMessageCount || 0);

    return postFormData(payload).then(function (data) {
      var elements = getElements();
      var serverMessages;
      if (!data || !data.success || !data.data || !data.data.messages) {
        return;
      }

      serverMessages = normalizeMessages(data.data.messages);
      if (!elements.chatMessages || !serverMessages.length) {
        return;
      }

      serverMessages.forEach(function (message) {
        if (message.role === 'assistant') {
          addAssistantReply(message.content || '', message.sender === 'human' ? 'watch-chat-human' : '', message.meta || null);
        } else {
          addChatMessage('user', message.content || '', '', message.meta || null);
        }
      });
      var currentSession = getCurrentChatSession();
      var mergedMessages = (currentSession && Array.isArray(currentSession.messages) ? currentSession.messages : []).concat(serverMessages);
      syncConversationMessages(chatConversationId, mergedMessages);
      markConversationRead(chatConversationId);
      scrollChatToBottom(true);
    }).catch(function () {});
  }

  function showChat(event) {
    if (event) {
      event.preventDefault();
      event.stopPropagation();
    }

    var elements = getElements();
    if (!elements.chat || !elements.overlay) {
      return;
    }

    document.documentElement.removeAttribute('data-widget-hidden');
    hideAllPanels(elements);
    elements.chat.classList.add('active');
    elements.overlay.classList.add('active');
    setExpandedState(elements);
    initializeChat();
    trackEvent('chat_open', { cta: 'chat' });
    markConversationRead(chatConversationId);
    startChatPolling();
    if (chatConversationId) {
      syncActiveConversationNow();
    }

    if (elements.chatIntake && elements.chatIntake.style.display !== 'none' && elements.chatLeadName) {
      elements.chatLeadName.focus();
    } else if (elements.chatInput) {
      elements.chatInput.focus();
    }
  }

  function hideChat(event) {
    if (event) {
      event.preventDefault();
      event.stopPropagation();
    }

    var elements = getElements();
    hideAllPanels(elements);
    if (elements.menu) {
      elements.menu.classList.add('active');
      setExpandedState(elements);
    }
  }

  function callUs(event) {
    if (event) {
      event.preventDefault();
      event.stopPropagation();
    }

    trackEvent('call_click', { cta: 'call' });
    if (smsNumber) {
      window.location.href = 'tel:' + smsNumber;
    }
  }

  function textUs(event) {
    if (event) {
      event.preventDefault();
      event.stopPropagation();
    }

    trackEvent('text_click', { cta: 'sms' });
    if (smsNumber) {
      window.location.href = 'sms:' + smsNumber;
    }
  }

  function handleActionKeydown(handler) {
    return function (event) {
      if (event.key === 'Enter' || event.key === ' ') {
        handler(event);
      }
    };
  }

  function bindAction(action, handler) {
    if (!action) {
      return;
    }

    action.addEventListener('click', handler);
    action.addEventListener('keydown', handleActionKeydown(handler));
  }

  function setChatLoading(isLoading) {
    var elements = getElements();
    if (elements.chatSend) {
      elements.chatSend.disabled = isLoading;
    }
    if (elements.chatStart) {
      elements.chatStart.disabled = isLoading;
    }
    if (elements.chatInput) {
      elements.chatInput.disabled = isLoading;
    }
  }

  function startChatSession(event) {
    var elements = getElements();
    var lead;
    var payload;
    var context;

    if (event) {
      event.preventDefault();
    }

    if (!startNonce) {
      return;
    }

    lead = collectLeadInputs();
    if (!lead.name || !lead.email || !lead.phone) {
      showToast(strings.error || 'Please complete your details first.');
      return;
    }

    payload = new window.FormData();
    context = getTrackingContext();
    Object.keys(context).forEach(function (key) {
      payload.append(key, context[key]);
    });
    payload.append('action', 'criaw_start_chat');
    payload.append('nonce', startNonce);
    payload.append('lead_name', lead.name);
    payload.append('lead_email', lead.email);
    payload.append('lead_phone', lead.phone);
    payload.append('lead_need', lead.looking_for);
    payload.append('email_updates_opt_in', elements.chatConsent && elements.chatConsent.checked ? '1' : '0');
    payload.append('sms_updates_opt_in', elements.chatSmsConsent && elements.chatSmsConsent.checked ? '1' : '0');
    payload.append('whatsapp_updates_opt_in', elements.chatWhatsappConsent && elements.chatWhatsappConsent.checked ? '1' : '0');

    chatRequestInFlight = true;
    setChatLoading(true);
    postFormData(payload).then(function (data) {
      if (!data || !data.success || !data.data) {
        showToast((data && data.data && data.data.message) || strings.error || 'Unable to start chat.');
        return;
      }

      chatConversationId = data.data.conversation_id || null;
      setStoredLead({
        name: lead.name,
        email: lead.email,
        phone: lead.phone,
        looking_for: lead.looking_for
      });
      setCurrentChatSession({
        conversationId: chatConversationId,
        messages: [],
        lead: getStoredLead()
      });
      var elementsNow = getElements();
      if (elementsNow.chatMessages) {
        elementsNow.chatMessages.innerHTML = '';
      }
      toggleIntake(false);
      if (lead.looking_for) {
        addChatMessage('user', lead.looking_for);
      }
      addAssistantReply(data.data.reply || welcomeMessage, '', {
        catalogCards: data.data.catalog_cards || [],
        catalogLinks: data.data.catalog_links || [],
        contactActions: data.data.contact_actions || []
      });
      startChatPolling();
    }).catch(function () {
      showToast(strings.error || 'Unable to start chat.');
    }).finally(function () {
      chatRequestInFlight = false;
      setChatLoading(false);
    });
  }

  function handleChatUpload() {
    var elements = getElements();
    var file;
    var payload;

    if (!elements.chatUpload || !elements.chatUpload.files || !elements.chatUpload.files[0] || !uploadNonce) {
      return;
    }

    file = elements.chatUpload.files[0];
    payload = new window.FormData();
    payload.append('action', 'criaw_chat_upload');
    payload.append('nonce', uploadNonce);
    payload.append('chat_image', file);

    if (elements.chatUploadStatus) {
      elements.chatUploadStatus.textContent = strings.uploading || 'Uploading...';
    }

    window.fetch(ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      body: payload
    }).then(function (response) {
      return response.json();
    }).then(function (data) {
      if (!data || !data.success || !data.data || !data.data.url) {
        if (elements.chatUploadStatus) {
          elements.chatUploadStatus.textContent = (strings.error || 'Upload failed.');
        }
        return;
      }

      pendingUploads.push({ url: data.data.url, name: file && file.name ? file.name : 'Photo' });
      renderPendingUploads();
      if (elements.chatInput) { elements.chatInput.focus(); }
      if (elements.chatUploadStatus) {
        elements.chatUploadStatus.textContent = 'Photo attached.';
      }
    }).catch(function () {
      if (elements.chatUploadStatus) {
        elements.chatUploadStatus.textContent = (strings.error || 'Upload failed.');
      }
    });
  }

  function sendChatMessage(event) {
    if (event) {
      event.preventDefault();
    }

    var elements = getElements();
    if (!elements.chatInput || !chatNonce) {
      return;
    }

    if (!chatConversationId) {
      startChatSession();
      return;
    }

    var message = elements.chatInput.value.trim();
    if (!message && !pendingUploads.length) {
      return;
    }

    var uploadsToSend = pendingUploads.slice();
    var userRow = addChatMessage('user', message || (uploadsToSend.length ? 'Photo attached.' : ''));
    appendUserMedia(userRow, uploadsToSend);
    elements.chatInput.value = '';
    elements.chatInput.style.height = 'auto';
    chatRequestInFlight = true;
    setChatLoading(true);

    var typing = addChatMessage('assistant', strings.thinking || 'Typing...', 'watch-chat-typing');
    var payload = new window.FormData();
    var context = getTrackingContext();

    Object.keys(context).forEach(function (key) {
      payload.append(key, context[key]);
    });
    payload.append('email_updates_opt_in', elements.chatConsent && elements.chatConsent.checked ? '1' : '0');
    payload.append('sms_updates_opt_in', elements.chatSmsConsent && elements.chatSmsConsent.checked ? '1' : '0');
    payload.append('whatsapp_updates_opt_in', elements.chatWhatsappConsent && elements.chatWhatsappConsent.checked ? '1' : '0');

    payload.append('action', 'criaw_chat_message');
    payload.append('nonce', chatNonce);
    var outgoing = message;
    if (uploadsToSend.length) {
      uploadsToSend.forEach(function (item) {
        outgoing = (outgoing ? outgoing + '\n' : '') + 'Photo: ' + item.url;
      });
    }
    payload.append('message', outgoing);
    if (chatConversationId) {
      payload.append('conversation_id', chatConversationId);
    }

    postFormData(payload).then(function (data) {
      if (typing && typing.parentNode) {
        typing.parentNode.removeChild(typing);
      }

      if (!data || !data.success || !data.data) {
        addChatMessage('assistant', (data && data.data && data.data.message) || strings.error || 'Sorry, something went wrong.');
        return;
      }

      chatConversationId = data.data.conversation_id || chatConversationId;
      addAssistantReply(data.data.reply || '', data.data.human_takeover ? 'watch-chat-human' : '', {
        catalogCards: data.data.catalog_cards || [],
        catalogLinks: data.data.catalog_links || [],
        contactActions: data.data.contact_actions || []
      });
      pendingUploads = [];
      renderPendingUploads();
      if (elements.chatUploadStatus) {
        elements.chatUploadStatus.textContent = '';
      }
      notifyIncomingMessage({ role: 'assistant', sender: (data.data.human_takeover ? 'human' : 'bot') });
      upsertArchivedConversation(getCurrentChatSession());
      markConversationRead(chatConversationId);
      startChatPolling();
    }).catch(function () {
      if (typing && typing.parentNode) {
        typing.parentNode.removeChild(typing);
      }

      addChatMessage('assistant', strings.error || 'Sorry, something went wrong.');
    }).finally(function () {
      chatRequestInFlight = false;
      setChatLoading(false);
      if (elements.chatInput) {
        elements.chatInput.focus();
      }
    });
  }

  function bindWidgetFormTracking(widget) {
    var formPopup = widget.querySelector('#watchFormPopup');
    if (!formPopup) {
      return;
    }

    function handleWidgetSubmitSuccess() {
      if (pendingWidgetSubmission) {
        trackEvent('form_submit', { cta: 'form' });
        pendingWidgetSubmission = false;
      }

      window.setTimeout(closeWidget, 1500);
    }

    formPopup.addEventListener('submit', function (event) {
      if (event.target && formPopup.contains(event.target)) {
        pendingWidgetSubmission = true;
      }
    }, true);

    document.addEventListener('wpformsAjaxSubmitSuccess', handleWidgetSubmitSuccess);
    document.addEventListener('wpformsSubmitSuccess', handleWidgetSubmitSuccess);

    if (typeof window.jQuery !== 'undefined') {
      window.jQuery(document).on('wpformsAjaxSubmitSuccess wpformsSubmitSuccess', handleWidgetSubmitSuccess);
    }
  }

  document.addEventListener('DOMContentLoaded', function () {
    var elements = getElements();
    if (!elements.widget) {
      return;
    }

    renderPendingUploads();
    updateUnreadBadge();
    startChatPolling();
    if (elements.chatConsent) {
      elements.chatConsent.checked = !!getConsentPreference();
      elements.chatConsent.addEventListener('change', function () {
        setConsentPreference(elements.chatConsent.checked);
      });
    }
    if (elements.chatSmsConsent) {
      elements.chatSmsConsent.checked = !!getSmsConsentPreference();
      elements.chatSmsConsent.addEventListener('change', function () {
        setSmsConsentPreference(elements.chatSmsConsent.checked);
      });
    }
    if (elements.chatWhatsappConsent) {
      elements.chatWhatsappConsent.checked = !!getWhatsappConsentPreference();
      elements.chatWhatsappConsent.addEventListener('change', function () {
        setWhatsappConsentPreference(elements.chatWhatsappConsent.checked);
      });
    }

    if (elements.launcherButton) {
      elements.launcherButton.addEventListener('click', toggleWidget);
    }
    if (elements.launcherLabel) {
      elements.launcherLabel.addEventListener('click', toggleWidget);
    }
    if (elements.overlay) {
      elements.overlay.addEventListener('click', closeWidget);
    }
    if (elements.menuClose) {
      elements.menuClose.addEventListener('click', closeWidget);
    }
    if (elements.formBack) {
      elements.formBack.addEventListener('click', hideForm);
    }
    if (elements.formClose) {
      elements.formClose.addEventListener('click', closeWidget);
    }
    if (elements.chatBack) {
      elements.chatBack.addEventListener('click', hideChat);
    }
    if (elements.chatClose) {
      elements.chatClose.addEventListener('click', closeWidget);
    }
    if (elements.chatHistoryClose) {
      elements.chatHistoryClose.addEventListener('click', function () { toggleHistoryPanel(false); });
    }
    if (elements.chatForm) {
      elements.chatForm.addEventListener('submit', sendChatMessage);
    }
    if (elements.chatStart) {
      elements.chatStart.addEventListener('click', startChatSession);
    }
    if (elements.chatPreviousBtn) {
      elements.chatPreviousBtn.addEventListener('click', function () { toggleHistoryPanel(true); });
    }
    if (elements.chatResetBtn) {
      elements.chatResetBtn.addEventListener('click', resetCurrentChat);
    }
    if (elements.chatUpload) {
      elements.chatUpload.addEventListener('change', handleChatUpload);
    }
    if (elements.chatInput) {
      elements.chatInput.addEventListener('keydown', function (event) {
        if (event.key === 'Enter' && !event.shiftKey) {
          event.preventDefault();
          sendChatMessage();
        }
      });
      elements.chatInput.addEventListener('input', function () {
        this.style.height = 'auto';
        this.style.height = Math.min(this.scrollHeight, 120) + 'px';
      });
    }

    bindAction(document.querySelector('[data-watch-action="chat"]'), showChat);
    bindAction(document.querySelector('[data-watch-action="show-form"]'), showForm);
    bindAction(document.querySelector('[data-watch-action="call"]'), callUs);
    bindAction(document.querySelector('[data-watch-action="text"]'), textUs);

    document.querySelectorAll('.trigger-instant-actions').forEach(function (button) {
      button.addEventListener('click', toggleWidget);
    });

    bindWidgetFormTracking(elements.widget);

    document.addEventListener('click', function (event) {
      var active =
        (elements.menu && elements.menu.classList.contains('active')) ||
        (elements.form && elements.form.classList.contains('active')) ||
        (elements.chat && elements.chat.classList.contains('active'));

      if (!active) {
        return;
      }

      if (event.target.closest && event.target.closest('.lity')) {
        return;
      }

      if (elements.widget.contains(event.target)) {
        return;
      }

      closeWidget(event);
    }, true);

    document.addEventListener('keydown', function (event) {
      var active =
        (elements.menu && elements.menu.classList.contains('active')) ||
        (elements.form && elements.form.classList.contains('active')) ||
        (elements.chat && elements.chat.classList.contains('active'));

      if (active && (event.key === 'Escape' || event.keyCode === 27)) {
        closeWidget();
      }
    });

    document.addEventListener('lity:open', function () {
      closeWidget();
    });
  });

  /* Resize listener removed: closing panels on resize broke mobile UX because
     the virtual keyboard triggers a resize event when it opens, which would
     immediately dismiss the chat panel after the user tapped the input. */
}());
