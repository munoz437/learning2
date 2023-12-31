import {SiteConfigContextValue} from './site-config-context';
import {WorkspaceInviteNotificationRenderer} from '../../workspace/notifications/workspace-invite-notification-renderer';
import {message} from '../../i18n/message';
import {User} from '@common/auth/user';

const workspaceInviteNotif =
  'Common\\Workspaces\\Notifications\\WorkspaceInvitation';

export const BaseSiteConfig: SiteConfigContextValue = {
  auth: {
    redirectUri: '/',
    adminRedirectUri: '/admin',
    getUserProfileLink: (user: User) => `/users/${user.id}`,
  },
  tags: {
    types: [{name: 'custom'}],
  },
  customPages: {
    types: [{type: 'default', label: message('Default')}],
  },
  notifications: {
    renderMap: {
      [workspaceInviteNotif]: WorkspaceInviteNotificationRenderer,
    },
  },
  admin: {
    ads: [],
  },
  demo: {
    loginPageDefaults: 'singleAccount',
  },
  homepage: {
    options: [
      {label: message('Login page'), value: 'loginPage'},
      {label: message('Registration page'), value: 'registerPage'},
    ],
  },
};
