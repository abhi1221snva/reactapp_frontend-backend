/**
 * Team Chat Embeddable Widget
 * Copy and paste this script on any third-party platform
 * No coding required - just add your widget token
 */
(function() {
    'use strict';

    // Prevent multiple initializations
    if (window.TeamChatWidgetLoaded) return;
    window.TeamChatWidgetLoaded = true;

    var TeamChatWidget = {
        config: null,
        container: null,
        pusher: null,
        isOpen: false,
        currentView: 'conversations',
        currentConversation: null,
        conversations: [],
        messages: [],
        onlineUsers: [],
        unreadCount: 0,
        typingUsers: {},
        messagePollingInterval: null,

        // Initialize the widget
        init: function(options) {
            if (!options.token) {
                console.error('TeamChatWidget: Token is required');
                return;
            }

            this.config = {
                token: options.token,
                baseUrl: options.baseUrl || window.TEAM_CHAT_BASE_URL || '',
                position: options.position || 'bottom-right',
                theme: options.theme || 'light',
                primaryColor: options.primaryColor || '#4F46E5',
                title: options.title || 'Team Chat',
                welcomeMessage: options.welcomeMessage || 'Welcome to Team Chat!',
                showOnlineStatus: options.showOnlineStatus !== false,
                enableNotifications: options.enableNotifications !== false,
                enableSounds: options.enableSounds !== false,
                autoOpen: options.autoOpen || false,
                zIndex: options.zIndex || 9999
            };

            this.validateToken();
        },

        // Validate the widget token
        validateToken: function() {
            var self = this;
            this.apiRequest('GET', '/team-chat/widget/validate', null, function(response) {
                if (response.success) {
                    self.config.user = response.data.user;
                    self.config.pusherKey = response.data.pusher_key;
                    self.config.pusherCluster = response.data.pusher_cluster;
                    self.config.parentId = response.data.parent_id;
                    self.createWidget();
                    self.loadConversations();
                    self.initPusher();
                    self.loadUnreadCount();
                    if (self.config.autoOpen) {
                        self.open();
                    }
                } else {
                    console.error('TeamChatWidget: Invalid token');
                }
            }, function(error) {
                console.error('TeamChatWidget: Token validation failed', error);
            });
        },

        // Create the widget HTML
        createWidget: function() {
            this.injectStyles();

            var container = document.createElement('div');
            container.id = 'tcw-container';
            container.className = 'tcw-container tcw-' + this.config.position + ' tcw-theme-' + this.config.theme;
            container.style.zIndex = this.config.zIndex;

            container.innerHTML = this.getWidgetHTML();
            document.body.appendChild(container);
            this.container = container;

            this.bindEvents();
        },

        // Get widget HTML template
        getWidgetHTML: function() {
            return `
                <div class="tcw-toggle" id="tcw-toggle">
                    <svg class="tcw-icon-chat" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
                    </svg>
                    <svg class="tcw-icon-close tcw-hidden" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                    <span class="tcw-badge tcw-hidden" id="tcw-badge">0</span>
                </div>

                <div class="tcw-window tcw-hidden" id="tcw-window">
                    <div class="tcw-header">
                        <div class="tcw-header-left">
                            <button class="tcw-back-btn tcw-hidden" id="tcw-back-btn">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <polyline points="15 18 9 12 15 6"></polyline>
                                </svg>
                            </button>
                            <div class="tcw-header-info">
                                <h3 class="tcw-title" id="tcw-title">${this.config.title}</h3>
                                <span class="tcw-subtitle" id="tcw-subtitle">${this.config.user ? this.config.user.name : ''}</span>
                            </div>
                        </div>
                        <div class="tcw-header-right">
                            <button class="tcw-new-chat-btn" id="tcw-new-chat-btn" title="New Chat">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <line x1="12" y1="5" x2="12" y2="19"></line>
                                    <line x1="5" y1="12" x2="19" y2="12"></line>
                                </svg>
                            </button>
                            <button class="tcw-close-btn" id="tcw-close-btn">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <line x1="18" y1="6" x2="6" y2="18"></line>
                                    <line x1="6" y1="6" x2="18" y2="18"></line>
                                </svg>
                            </button>
                        </div>
                    </div>

                    <div class="tcw-body">
                        <!-- Conversations List View -->
                        <div class="tcw-view tcw-conversations-view" id="tcw-conversations-view">
                            <div class="tcw-search-box">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="11" cy="11" r="8"></circle>
                                    <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                                </svg>
                                <input type="text" id="tcw-search" placeholder="Search conversations...">
                            </div>
                            <div class="tcw-conversations-list" id="tcw-conversations-list">
                                <div class="tcw-loading">Loading...</div>
                            </div>
                        </div>

                        <!-- Chat View -->
                        <div class="tcw-view tcw-chat-view tcw-hidden" id="tcw-chat-view">
                            <div class="tcw-messages" id="tcw-messages">
                                <div class="tcw-loading">Loading messages...</div>
                            </div>
                            <div class="tcw-typing-indicator tcw-hidden" id="tcw-typing-indicator">
                                <span></span> is typing...
                            </div>
                            <div class="tcw-input-area">
                                <button class="tcw-attach-btn" id="tcw-attach-btn" title="Attach file">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"></path>
                                    </svg>
                                </button>
                                <input type="file" id="tcw-file-input" class="tcw-hidden" multiple>
                                <textarea id="tcw-message-input" placeholder="Type a message..." rows="1"></textarea>
                                <button class="tcw-send-btn" id="tcw-send-btn" title="Send message">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <line x1="22" y1="2" x2="11" y2="13"></line>
                                        <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
                                    </svg>
                                </button>
                            </div>
                        </div>

                        <!-- New Chat View -->
                        <div class="tcw-view tcw-new-chat-view tcw-hidden" id="tcw-new-chat-view">
                            <div class="tcw-search-box">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="11" cy="11" r="8"></circle>
                                    <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                                </svg>
                                <input type="text" id="tcw-user-search" placeholder="Search users...">
                            </div>
                            <div class="tcw-users-list" id="tcw-users-list">
                                <div class="tcw-loading">Search for users...</div>
                            </div>
                        </div>
                    </div>
                </div>
            `;
        },

        // Inject CSS styles
        injectStyles: function() {
            if (document.getElementById('tcw-styles')) return;

            var primaryColor = this.config.primaryColor;
            var style = document.createElement('style');
            style.id = 'tcw-styles';
            style.textContent = this.getStyles(primaryColor);
            document.head.appendChild(style);
        },

        // Get CSS styles
        getStyles: function(primaryColor) {
            return `
                .tcw-container * { box-sizing: border-box; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif; }
                .tcw-container { position: fixed; }
                .tcw-bottom-right { bottom: 20px; right: 20px; }
                .tcw-bottom-left { bottom: 20px; left: 20px; }
                .tcw-top-right { top: 20px; right: 20px; }
                .tcw-top-left { top: 20px; left: 20px; }
                .tcw-hidden { display: none !important; }

                .tcw-toggle {
                    width: 60px; height: 60px; border-radius: 50%; background: ${primaryColor};
                    border: none; cursor: pointer; display: flex; align-items: center; justify-content: center;
                    box-shadow: 0 4px 12px rgba(0,0,0,0.15); transition: transform 0.2s, box-shadow 0.2s;
                    position: relative; color: white;
                }
                .tcw-toggle:hover { transform: scale(1.05); box-shadow: 0 6px 16px rgba(0,0,0,0.2); }
                .tcw-toggle svg { width: 28px; height: 28px; }
                .tcw-badge {
                    position: absolute; top: -5px; right: -5px; background: #EF4444;
                    color: white; font-size: 12px; font-weight: 600; min-width: 20px;
                    height: 20px; border-radius: 10px; display: flex; align-items: center;
                    justify-content: center; padding: 0 6px;
                }

                .tcw-window {
                    position: absolute; width: 380px; height: 520px; background: white;
                    border-radius: 16px; box-shadow: 0 10px 40px rgba(0,0,0,0.15);
                    display: flex; flex-direction: column; overflow: hidden;
                }
                .tcw-bottom-right .tcw-window { bottom: 80px; right: 0; }
                .tcw-bottom-left .tcw-window { bottom: 80px; left: 0; }
                .tcw-top-right .tcw-window { top: 80px; right: 0; }
                .tcw-top-left .tcw-window { top: 80px; left: 0; }

                .tcw-header {
                    background: ${primaryColor}; color: white; padding: 16px;
                    display: flex; align-items: center; justify-content: space-between;
                }
                .tcw-header-left { display: flex; align-items: center; gap: 8px; }
                .tcw-header-right { display: flex; align-items: center; gap: 8px; }
                .tcw-header-info { display: flex; flex-direction: column; }
                .tcw-title { margin: 0; font-size: 16px; font-weight: 600; }
                .tcw-subtitle { font-size: 12px; opacity: 0.8; }
                .tcw-back-btn, .tcw-close-btn, .tcw-new-chat-btn {
                    background: transparent; border: none; color: white; cursor: pointer;
                    padding: 4px; border-radius: 4px; display: flex; align-items: center; justify-content: center;
                }
                .tcw-back-btn:hover, .tcw-close-btn:hover, .tcw-new-chat-btn:hover { background: rgba(255,255,255,0.1); }
                .tcw-back-btn svg, .tcw-close-btn svg, .tcw-new-chat-btn svg { width: 20px; height: 20px; }

                .tcw-body { flex: 1; display: flex; flex-direction: column; overflow: hidden; }
                .tcw-view { flex: 1; display: flex; flex-direction: column; overflow: hidden; }

                .tcw-search-box {
                    padding: 12px; border-bottom: 1px solid #E5E7EB; display: flex;
                    align-items: center; gap: 8px; background: #F9FAFB;
                }
                .tcw-search-box svg { width: 18px; height: 18px; color: #9CA3AF; flex-shrink: 0; }
                .tcw-search-box input {
                    flex: 1; border: none; background: transparent; font-size: 14px;
                    outline: none; color: #374151;
                }
                .tcw-search-box input::placeholder { color: #9CA3AF; }

                .tcw-conversations-list, .tcw-users-list {
                    flex: 1; overflow-y: auto; padding: 8px;
                }
                .tcw-conversation-item, .tcw-user-item {
                    display: flex; align-items: center; gap: 12px; padding: 12px;
                    border-radius: 8px; cursor: pointer; transition: background 0.15s;
                }
                .tcw-conversation-item:hover, .tcw-user-item:hover { background: #F3F4F6; }
                .tcw-avatar {
                    width: 44px; height: 44px; border-radius: 50%; background: ${primaryColor};
                    color: white; display: flex; align-items: center; justify-content: center;
                    font-weight: 600; font-size: 16px; flex-shrink: 0; position: relative;
                }
                .tcw-avatar img { width: 100%; height: 100%; border-radius: 50%; object-fit: cover; }
                .tcw-online-dot {
                    position: absolute; bottom: 2px; right: 2px; width: 10px; height: 10px;
                    background: #22C55E; border: 2px solid white; border-radius: 50%;
                }
                .tcw-conv-info { flex: 1; min-width: 0; }
                .tcw-conv-name { font-weight: 500; font-size: 14px; color: #111827; margin-bottom: 2px; }
                .tcw-conv-preview { font-size: 13px; color: #6B7280; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
                .tcw-conv-meta { display: flex; flex-direction: column; align-items: flex-end; gap: 4px; }
                .tcw-conv-time { font-size: 11px; color: #9CA3AF; }
                .tcw-conv-unread {
                    background: ${primaryColor}; color: white; font-size: 11px;
                    font-weight: 600; min-width: 18px; height: 18px; border-radius: 9px;
                    display: flex; align-items: center; justify-content: center; padding: 0 5px;
                }

                .tcw-messages { flex: 1; overflow-y: auto; padding: 16px; display: flex; flex-direction: column; gap: 8px; }
                .tcw-message { display: flex; gap: 8px; max-width: 85%; }
                .tcw-message-sent { align-self: flex-end; flex-direction: row-reverse; }
                .tcw-message-received { align-self: flex-start; }
                .tcw-message-avatar { width: 28px; height: 28px; border-radius: 50%; background: #E5E7EB; flex-shrink: 0; display: flex; align-items: center; justify-content: center; font-size: 10px; font-weight: 600; color: #6B7280; }
                .tcw-message-content {
                    padding: 10px 14px; border-radius: 16px; font-size: 14px; line-height: 1.4;
                }
                .tcw-message-sent .tcw-message-content { background: ${primaryColor}; color: white; border-bottom-right-radius: 4px; }
                .tcw-message-received .tcw-message-content { background: #F3F4F6; color: #111827; border-bottom-left-radius: 4px; }
                .tcw-message-time { font-size: 10px; opacity: 0.7; margin-top: 4px; }
                .tcw-message-sent .tcw-message-time { text-align: right; }
                .tcw-message-attachment { margin-top: 8px; }
                .tcw-message-attachment img { max-width: 200px; max-height: 150px; border-radius: 8px; cursor: pointer; }
                .tcw-message-attachment a { color: inherit; text-decoration: underline; }

                .tcw-typing-indicator { padding: 8px 16px; font-size: 12px; color: #6B7280; font-style: italic; }

                .tcw-input-area {
                    padding: 12px; border-top: 1px solid #E5E7EB;
                    display: flex; align-items: flex-end; gap: 8px; background: white;
                }
                .tcw-attach-btn, .tcw-send-btn {
                    width: 36px; height: 36px; border-radius: 50%; border: none;
                    cursor: pointer; display: flex; align-items: center; justify-content: center;
                    flex-shrink: 0; transition: background 0.15s;
                }
                .tcw-attach-btn { background: #F3F4F6; color: #6B7280; }
                .tcw-attach-btn:hover { background: #E5E7EB; }
                .tcw-send-btn { background: ${primaryColor}; color: white; }
                .tcw-send-btn:hover { opacity: 0.9; }
                .tcw-attach-btn svg, .tcw-send-btn svg { width: 18px; height: 18px; }
                #tcw-message-input {
                    flex: 1; border: 1px solid #E5E7EB; border-radius: 20px; padding: 8px 14px;
                    font-size: 14px; resize: none; max-height: 100px; outline: none;
                    transition: border-color 0.15s;
                }
                #tcw-message-input:focus { border-color: ${primaryColor}; }

                .tcw-loading { padding: 20px; text-align: center; color: #9CA3AF; font-size: 14px; }
                .tcw-empty { padding: 40px 20px; text-align: center; color: #9CA3AF; }
                .tcw-empty svg { width: 48px; height: 48px; margin-bottom: 12px; opacity: 0.5; }
                .tcw-empty p { margin: 0; font-size: 14px; }

                .tcw-date-separator { text-align: center; margin: 16px 0; }
                .tcw-date-separator span {
                    background: #E5E7EB; color: #6B7280; font-size: 11px;
                    padding: 4px 12px; border-radius: 10px;
                }

                /* Dark theme */
                .tcw-theme-dark .tcw-window { background: #1F2937; }
                .tcw-theme-dark .tcw-search-box { background: #374151; border-color: #4B5563; }
                .tcw-theme-dark .tcw-search-box input { color: #F3F4F6; }
                .tcw-theme-dark .tcw-conversation-item:hover, .tcw-theme-dark .tcw-user-item:hover { background: #374151; }
                .tcw-theme-dark .tcw-conv-name { color: #F3F4F6; }
                .tcw-theme-dark .tcw-conv-preview { color: #9CA3AF; }
                .tcw-theme-dark .tcw-message-received .tcw-message-content { background: #374151; color: #F3F4F6; }
                .tcw-theme-dark .tcw-input-area { background: #1F2937; border-color: #4B5563; }
                .tcw-theme-dark #tcw-message-input { background: #374151; border-color: #4B5563; color: #F3F4F6; }
                .tcw-theme-dark .tcw-attach-btn { background: #374151; color: #9CA3AF; }
                .tcw-theme-dark .tcw-date-separator span { background: #374151; }

                /* Mobile responsive */
                @media (max-width: 480px) {
                    .tcw-window { width: calc(100vw - 40px); height: calc(100vh - 120px); }
                }
            `;
        },

        // Bind event listeners
        bindEvents: function() {
            var self = this;

            // Toggle button
            document.getElementById('tcw-toggle').addEventListener('click', function() {
                self.toggle();
            });

            // Close button
            document.getElementById('tcw-close-btn').addEventListener('click', function() {
                self.close();
            });

            // Back button
            document.getElementById('tcw-back-btn').addEventListener('click', function() {
                self.showConversations();
            });

            // New chat button
            document.getElementById('tcw-new-chat-btn').addEventListener('click', function() {
                self.showNewChat();
            });

            // Search conversations
            document.getElementById('tcw-search').addEventListener('input', function(e) {
                self.filterConversations(e.target.value);
            });

            // Search users
            document.getElementById('tcw-user-search').addEventListener('input', function(e) {
                self.searchUsers(e.target.value);
            });

            // Send message
            document.getElementById('tcw-send-btn').addEventListener('click', function() {
                self.sendMessage();
            });

            // Message input
            var messageInput = document.getElementById('tcw-message-input');
            messageInput.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    self.sendMessage();
                }
            });
            messageInput.addEventListener('input', function() {
                self.sendTypingIndicator();
                self.autoResizeTextarea(this);
            });

            // Attach file
            document.getElementById('tcw-attach-btn').addEventListener('click', function() {
                document.getElementById('tcw-file-input').click();
            });
            document.getElementById('tcw-file-input').addEventListener('change', function(e) {
                self.uploadAttachment(e.target.files);
            });
        },

        // Toggle widget open/close
        toggle: function() {
            if (this.isOpen) {
                this.close();
            } else {
                this.open();
            }
        },

        // Open widget
        open: function() {
            this.isOpen = true;
            document.getElementById('tcw-window').classList.remove('tcw-hidden');
            document.querySelector('.tcw-icon-chat').classList.add('tcw-hidden');
            document.querySelector('.tcw-icon-close').classList.remove('tcw-hidden');
        },

        // Close widget
        close: function() {
            this.isOpen = false;
            document.getElementById('tcw-window').classList.add('tcw-hidden');
            document.querySelector('.tcw-icon-chat').classList.remove('tcw-hidden');
            document.querySelector('.tcw-icon-close').classList.add('tcw-hidden');
        },

        // Show conversations view
        showConversations: function() {
            this.currentView = 'conversations';
            this.currentConversation = null;
            document.getElementById('tcw-conversations-view').classList.remove('tcw-hidden');
            document.getElementById('tcw-chat-view').classList.add('tcw-hidden');
            document.getElementById('tcw-new-chat-view').classList.add('tcw-hidden');
            document.getElementById('tcw-back-btn').classList.add('tcw-hidden');
            document.getElementById('tcw-new-chat-btn').classList.remove('tcw-hidden');
            document.getElementById('tcw-title').textContent = this.config.title;
            document.getElementById('tcw-subtitle').textContent = this.config.user ? this.config.user.name : '';
            this.loadConversations();
        },

        // Show chat view
        showChat: function(conversation) {
            this.currentView = 'chat';
            this.currentConversation = conversation;
            document.getElementById('tcw-conversations-view').classList.add('tcw-hidden');
            document.getElementById('tcw-chat-view').classList.remove('tcw-hidden');
            document.getElementById('tcw-new-chat-view').classList.add('tcw-hidden');
            document.getElementById('tcw-back-btn').classList.remove('tcw-hidden');
            document.getElementById('tcw-new-chat-btn').classList.add('tcw-hidden');
            document.getElementById('tcw-title').textContent = conversation.name || conversation.display_name;
            document.getElementById('tcw-subtitle').textContent = conversation.type === 'group' ? (conversation.participant_count || 0) + ' members' : '';
            this.loadMessages(conversation.uuid);
            this.markAsRead(conversation.uuid);
        },

        // Show new chat view
        showNewChat: function() {
            this.currentView = 'new-chat';
            document.getElementById('tcw-conversations-view').classList.add('tcw-hidden');
            document.getElementById('tcw-chat-view').classList.add('tcw-hidden');
            document.getElementById('tcw-new-chat-view').classList.remove('tcw-hidden');
            document.getElementById('tcw-back-btn').classList.remove('tcw-hidden');
            document.getElementById('tcw-new-chat-btn').classList.add('tcw-hidden');
            document.getElementById('tcw-title').textContent = 'New Chat';
            document.getElementById('tcw-subtitle').textContent = 'Select a user';
            document.getElementById('tcw-user-search').value = '';
            document.getElementById('tcw-users-list').innerHTML = '<div class="tcw-loading">Search for users...</div>';
        },

        // Load conversations
        loadConversations: function() {
            var self = this;
            this.apiRequest('GET', '/team-chat/widget/conversations', null, function(response) {
                if (response.success) {
                    self.conversations = response.data || [];
                    self.renderConversations();
                }
            });
        },

        // Render conversations list
        renderConversations: function(filter) {
            var container = document.getElementById('tcw-conversations-list');
            var conversations = this.conversations;

            if (filter) {
                var filterLower = filter.toLowerCase();
                conversations = conversations.filter(function(conv) {
                    var name = conv.name || conv.display_name || '';
                    return name.toLowerCase().indexOf(filterLower) !== -1;
                });
            }

            if (conversations.length === 0) {
                container.innerHTML = '<div class="tcw-empty"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg><p>No conversations yet</p></div>';
                return;
            }

            var self = this;
            container.innerHTML = conversations.map(function(conv) {
                var name = conv.name || conv.display_name || 'Unknown';
                var initials = self.getInitials(name);
                var preview = conv.last_message ? conv.last_message.body || 'Attachment' : 'No messages yet';
                var time = conv.last_message ? self.formatTime(conv.last_message.created_at) : '';
                var unread = conv.unread_count || 0;
                var avatarContent = conv.avatar ? '<img src="' + conv.avatar + '" alt="">' : initials;

                return '<div class="tcw-conversation-item" data-uuid="' + conv.uuid + '">' +
                    '<div class="tcw-avatar">' + avatarContent + '</div>' +
                    '<div class="tcw-conv-info">' +
                    '<div class="tcw-conv-name">' + self.escapeHtml(name) + '</div>' +
                    '<div class="tcw-conv-preview">' + self.escapeHtml(preview) + '</div>' +
                    '</div>' +
                    '<div class="tcw-conv-meta">' +
                    '<span class="tcw-conv-time">' + time + '</span>' +
                    (unread > 0 ? '<span class="tcw-conv-unread">' + unread + '</span>' : '') +
                    '</div></div>';
            }).join('');

            // Add click handlers
            container.querySelectorAll('.tcw-conversation-item').forEach(function(item) {
                item.addEventListener('click', function() {
                    var uuid = this.getAttribute('data-uuid');
                    var conv = self.conversations.find(function(c) { return c.uuid === uuid; });
                    if (conv) self.showChat(conv);
                });
            });
        },

        // Filter conversations
        filterConversations: function(query) {
            this.renderConversations(query);
        },

        // Search users
        searchUsers: function(query) {
            if (query.length < 2) {
                document.getElementById('tcw-users-list').innerHTML = '<div class="tcw-loading">Type at least 2 characters...</div>';
                return;
            }

            var self = this;
            this.apiRequest('GET', '/team-chat/widget/users/search?query=' + encodeURIComponent(query), null, function(response) {
                if (response.success) {
                    self.renderUsers(response.data || []);
                }
            });
        },

        // Render users list
        renderUsers: function(users) {
            var container = document.getElementById('tcw-users-list');
            var self = this;

            if (users.length === 0) {
                container.innerHTML = '<div class="tcw-empty"><p>No users found</p></div>';
                return;
            }

            container.innerHTML = users.map(function(user) {
                var name = (user.first_name || '') + ' ' + (user.last_name || '');
                name = name.trim() || user.email || 'Unknown';
                var initials = self.getInitials(name);
                var avatarContent = user.profile_pic ? '<img src="' + user.profile_pic + '" alt="">' : initials;
                var isOnline = user.presence_status === 'online';

                return '<div class="tcw-user-item" data-id="' + user.id + '">' +
                    '<div class="tcw-avatar">' + avatarContent + (isOnline ? '<span class="tcw-online-dot"></span>' : '') + '</div>' +
                    '<div class="tcw-conv-info">' +
                    '<div class="tcw-conv-name">' + self.escapeHtml(name) + '</div>' +
                    '<div class="tcw-conv-preview">' + self.escapeHtml(user.email || '') + '</div>' +
                    '</div></div>';
            }).join('');

            // Add click handlers
            container.querySelectorAll('.tcw-user-item').forEach(function(item) {
                item.addEventListener('click', function() {
                    var userId = this.getAttribute('data-id');
                    self.startDirectChat(userId);
                });
            });
        },

        // Start direct chat with user
        startDirectChat: function(userId) {
            var self = this;
            this.apiRequest('POST', '/team-chat/widget/conversations/direct', { user_id: userId }, function(response) {
                if (response.success) {
                    self.showChat(response.data);
                }
            });
        },

        // Load messages
        loadMessages: function(conversationUuid) {
            var self = this;
            document.getElementById('tcw-messages').innerHTML = '<div class="tcw-loading">Loading messages...</div>';

            this.apiRequest('GET', '/team-chat/widget/conversations/' + conversationUuid + '/messages', null, function(response) {
                if (response.success) {
                    self.messages = (response.data || []).reverse();
                    self.renderMessages();
                }
            });
        },

        // Render messages
        renderMessages: function() {
            var container = document.getElementById('tcw-messages');
            var self = this;
            var currentUserId = this.config.user ? this.config.user.id : null;

            if (this.messages.length === 0) {
                container.innerHTML = '<div class="tcw-empty"><p>No messages yet. Say hello!</p></div>';
                return;
            }

            var html = '';
            var lastDate = null;

            this.messages.forEach(function(msg) {
                var msgDate = self.formatDate(msg.created_at);
                if (msgDate !== lastDate) {
                    html += '<div class="tcw-date-separator"><span>' + msgDate + '</span></div>';
                    lastDate = msgDate;
                }

                var isSent = msg.sender_id == currentUserId;
                var senderName = msg.sender ? ((msg.sender.first_name || '') + ' ' + (msg.sender.last_name || '')).trim() : 'Unknown';
                var initials = self.getInitials(senderName);

                html += '<div class="tcw-message ' + (isSent ? 'tcw-message-sent' : 'tcw-message-received') + '">';
                if (!isSent) {
                    html += '<div class="tcw-message-avatar">' + initials + '</div>';
                }
                html += '<div class="tcw-message-bubble">';
                html += '<div class="tcw-message-content">' + self.escapeHtml(msg.body || '');

                // Attachments
                if (msg.attachments && msg.attachments.length > 0) {
                    msg.attachments.forEach(function(att) {
                        html += '<div class="tcw-message-attachment">';
                        if (att.mime_type && att.mime_type.startsWith('image/')) {
                            html += '<img src="' + self.config.baseUrl + '/team-chat/attachments/' + att.id + '/download" alt="' + self.escapeHtml(att.original_name) + '">';
                        } else {
                            html += '<a href="' + self.config.baseUrl + '/team-chat/attachments/' + att.id + '/download" target="_blank">' + self.escapeHtml(att.original_name) + '</a>';
                        }
                        html += '</div>';
                    });
                }

                html += '</div>';
                html += '<div class="tcw-message-time">' + self.formatTime(msg.created_at) + '</div>';
                html += '</div></div>';
            });

            container.innerHTML = html;
            container.scrollTop = container.scrollHeight;
        },

        // Send message
        sendMessage: function() {
            var input = document.getElementById('tcw-message-input');
            var message = input.value.trim();

            if (!message || !this.currentConversation) return;

            var self = this;
            input.value = '';
            this.autoResizeTextarea(input);

            // Optimistic update
            var tempMsg = {
                id: 'temp-' + Date.now(),
                body: message,
                sender_id: this.config.user.id,
                sender: this.config.user,
                created_at: new Date().toISOString()
            };
            this.messages.push(tempMsg);
            this.renderMessages();

            this.apiRequest('POST', '/team-chat/widget/conversations/' + this.currentConversation.uuid + '/messages', { body: message }, function(response) {
                if (response.success) {
                    // Replace temp message with real one
                    var index = self.messages.findIndex(function(m) { return m.id === tempMsg.id; });
                    if (index !== -1) {
                        self.messages[index] = response.data;
                        self.renderMessages();
                    }
                }
            });
        },

        // Upload attachment
        uploadAttachment: function(files) {
            if (!files || files.length === 0 || !this.currentConversation) return;

            var self = this;
            var formData = new FormData();
            for (var i = 0; i < files.length; i++) {
                formData.append('attachments[]', files[i]);
            }

            this.apiRequest('POST', '/team-chat/widget/conversations/' + this.currentConversation.uuid + '/attachments', formData, function(response) {
                if (response.success) {
                    self.loadMessages(self.currentConversation.uuid);
                }
            }, null, true);

            document.getElementById('tcw-file-input').value = '';
        },

        // Send typing indicator
        sendTypingIndicator: function() {
            if (!this.currentConversation) return;
            this.apiRequest('POST', '/team-chat/widget/conversations/' + this.currentConversation.uuid + '/typing', {});
        },

        // Mark conversation as read
        markAsRead: function(conversationUuid) {
            var self = this;
            this.apiRequest('POST', '/team-chat/widget/conversations/' + conversationUuid + '/read', {}, function() {
                self.loadUnreadCount();
            });
        },

        // Load unread count
        loadUnreadCount: function() {
            var self = this;
            this.apiRequest('GET', '/team-chat/widget/unread-count', null, function(response) {
                if (response.success) {
                    self.unreadCount = response.data.count || 0;
                    self.updateBadge();
                }
            });
        },

        // Update badge
        updateBadge: function() {
            var badge = document.getElementById('tcw-badge');
            if (this.unreadCount > 0) {
                badge.textContent = this.unreadCount > 99 ? '99+' : this.unreadCount;
                badge.classList.remove('tcw-hidden');
            } else {
                badge.classList.add('tcw-hidden');
            }
        },

        // Initialize Pusher
        initPusher: function() {
            if (!this.config.pusherKey || typeof Pusher === 'undefined') {
                // Fallback to polling
                this.startPolling();
                return;
            }

            var self = this;
            this.pusher = new Pusher(this.config.pusherKey, {
                cluster: this.config.pusherCluster,
                authEndpoint: this.config.baseUrl + '/team-chat/widget/pusher/auth',
                auth: {
                    headers: {
                        'Authorization': 'Bearer ' + this.config.token
                    }
                }
            });

            // Subscribe to user channel
            var userChannel = this.pusher.subscribe('private-team-user.' + this.config.parentId + '.' + this.config.user.id);

            userChannel.bind('new.message', function(data) {
                self.handleNewMessage(data);
            });

            userChannel.bind('presence.changed', function(data) {
                self.handlePresenceChange(data);
            });
        },

        // Handle new message from Pusher
        handleNewMessage: function(data) {
            if (this.config.enableNotifications) {
                this.showNotification(data);
            }

            if (this.config.enableSounds) {
                this.playSound();
            }

            this.loadUnreadCount();

            if (this.currentView === 'conversations') {
                this.loadConversations();
            } else if (this.currentView === 'chat' && this.currentConversation &&
                       data.conversation_uuid === this.currentConversation.uuid) {
                this.messages.push(data.message);
                this.renderMessages();
                this.markAsRead(this.currentConversation.uuid);
            }
        },

        // Handle presence change
        handlePresenceChange: function(data) {
            // Update UI if needed
        },

        // Start polling fallback
        startPolling: function() {
            var self = this;
            this.messagePollingInterval = setInterval(function() {
                self.loadUnreadCount();
                if (self.currentView === 'conversations') {
                    self.loadConversations();
                } else if (self.currentView === 'chat' && self.currentConversation) {
                    self.loadMessages(self.currentConversation.uuid);
                }
            }, 5000);
        },

        // Show notification
        showNotification: function(data) {
            if (!('Notification' in window)) return;

            if (Notification.permission === 'granted') {
                new Notification('New message', {
                    body: data.message ? data.message.body : 'You have a new message',
                    icon: this.config.baseUrl + '/images/chat-icon.png'
                });
            } else if (Notification.permission !== 'denied') {
                Notification.requestPermission();
            }
        },

        // Play notification sound
        playSound: function() {
            try {
                var audio = new Audio(this.config.baseUrl + '/sounds/notification.mp3');
                audio.volume = 0.5;
                audio.play().catch(function() {});
            } catch (e) {}
        },

        // Auto resize textarea
        autoResizeTextarea: function(textarea) {
            textarea.style.height = 'auto';
            textarea.style.height = Math.min(textarea.scrollHeight, 100) + 'px';
        },

        // API request helper
        apiRequest: function(method, endpoint, data, onSuccess, onError, isFormData) {
            var self = this;
            var xhr = new XMLHttpRequest();
            xhr.open(method, this.config.baseUrl + endpoint, true);
            xhr.setRequestHeader('Authorization', 'Bearer ' + this.config.token);
            xhr.setRequestHeader('Accept', 'application/json');

            if (!isFormData) {
                xhr.setRequestHeader('Content-Type', 'application/json');
            }

            xhr.onreadystatechange = function() {
                if (xhr.readyState === 4) {
                    try {
                        var response = JSON.parse(xhr.responseText);
                        if (xhr.status >= 200 && xhr.status < 300) {
                            if (onSuccess) onSuccess(response);
                        } else {
                            if (onError) onError(response);
                        }
                    } catch (e) {
                        if (onError) onError({ error: 'Parse error' });
                    }
                }
            };

            if (data) {
                xhr.send(isFormData ? data : JSON.stringify(data));
            } else {
                xhr.send();
            }
        },

        // Utility functions
        escapeHtml: function(text) {
            if (!text) return '';
            var div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        },

        getInitials: function(name) {
            if (!name) return '?';
            var parts = name.split(' ').filter(function(p) { return p; });
            if (parts.length >= 2) {
                return (parts[0][0] + parts[1][0]).toUpperCase();
            }
            return name.substring(0, 2).toUpperCase();
        },

        formatTime: function(dateString) {
            if (!dateString) return '';
            var date = new Date(dateString);
            var now = new Date();
            var diff = now - date;

            if (diff < 60000) return 'now';
            if (diff < 3600000) return Math.floor(diff / 60000) + 'm';
            if (diff < 86400000) return Math.floor(diff / 3600000) + 'h';
            if (diff < 604800000) return Math.floor(diff / 86400000) + 'd';

            return date.toLocaleDateString();
        },

        formatDate: function(dateString) {
            if (!dateString) return '';
            var date = new Date(dateString);
            var now = new Date();
            var today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
            var yesterday = new Date(today);
            yesterday.setDate(yesterday.getDate() - 1);

            if (date >= today) return 'Today';
            if (date >= yesterday) return 'Yesterday';

            return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
        }
    };

    // Expose to global
    window.TeamChatWidget = TeamChatWidget;

    // Auto-init if config is present
    if (window.TEAM_CHAT_CONFIG) {
        TeamChatWidget.init(window.TEAM_CHAT_CONFIG);
    }
})();
