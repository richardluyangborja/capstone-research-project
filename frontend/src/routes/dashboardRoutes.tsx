import type { ReactNode } from 'react'
import Billing from '../pages/Billing'
import Companies from '../pages/Companies'
import CompanyDetail from '../pages/CompanyDetail'
import CommunicationDetail from '../pages/CommunicationDetail'
import Communications from '../pages/Communications'
import CommunicationThreadDetail from '../pages/CommunicationThreadDetail'
import CommunicationThreads from '../pages/CommunicationThreads'
import CommunicationTemplateForm from '../pages/CommunicationTemplateForm'
import CommunicationTemplates from '../pages/CommunicationTemplates'
import Contacts from '../pages/Contacts'
import ContractDetail from '../pages/ContractDetail'
import Contracts from '../pages/Contracts'
import DashboardHome from '../pages/DashboardHome'
import Deployments from '../pages/Deployments'
import Employees from '../pages/Employees'
import EmployeeProfilePlaceholder from '../pages/EmployeeProfilePlaceholder'
import FollowUpForm from '../pages/FollowUpForm'
import FollowUps from '../pages/FollowUps'
import ImportLeads from '../pages/ImportLeads'
import LeadConvert from '../pages/LeadConvert'
import LeadDetail from '../pages/LeadDetail'
import LeadsList from '../pages/LeadsList'
import NewCommunication from '../pages/NewCommunication'
import NewCompany from '../pages/NewCompany'
import NewLead from '../pages/NewLead'
import NewOpportunity from '../pages/NewOpportunity'
import Opportunities from '../pages/Opportunities'
import OpportunityDetail from '../pages/OpportunityDetail'
import PersonalSettings from '../pages/PersonalSettings'
import Pipeline from '../pages/Pipeline'
import Reports from '../pages/Reports'
import Satisfaction from '../pages/Satisfaction'
import SurveyForm from '../pages/SurveyForm'
import SurveyInvitations from '../pages/SurveyInvitations'
import SurveyResponseDetail from '../pages/SurveyResponseDetail'
import SurveyResponses from '../pages/SurveyResponses'
import Surveys from '../pages/Surveys'
import AdminAudit from '../pages/admin/AdminAudit'
import AdminHome from '../pages/admin/AdminHome'
import AdminOperations from '../pages/admin/AdminOperations'
import AdminSettings from '../pages/admin/AdminSettings'
import AdminTeams from '../pages/admin/AdminTeams'
import AdminUsers from '../pages/admin/AdminUsers'
import PlatformDiagnostics from '../pages/platform/PlatformDiagnostics'
import PlatformHome from '../pages/platform/PlatformHome'
import { ADMIN_ROLES, PLATFORM_ROLES } from '../lib/permissions'
import type { AdminArea, Role } from '../types'

export interface DashboardRoute {
  path: string
  element: ReactNode
  /** The role boundary for an administration route (spec 19 S1). */
  roles?: Role[]
  /** The shared sub-navigation shown above an administration page. */
  area?: AdminArea
}

/**
 * Every page behind the shared dashboard shell. Keeping the inventory in one exported list
 * lets the sidebar test prove that no navigation entry points at a route that does not exist
 * (spec 16 §Navigation and routes — S19).
 */
export const dashboardRoutes: DashboardRoute[] = [
  { path: '/dashboard', element: <DashboardHome /> },
  { path: '/leads', element: <LeadsList /> },
  { path: '/leads/new', element: <NewLead /> },
  { path: '/leads/import', element: <ImportLeads /> },
  { path: '/leads/:id', element: <LeadDetail /> },
  { path: '/leads/:id/convert', element: <LeadConvert /> },
  { path: '/accounts', element: <Companies /> },
  { path: '/accounts/new', element: <NewCompany /> },
  { path: '/accounts/:id', element: <CompanyDetail /> },
  { path: '/contracts', element: <Contracts /> },
  { path: '/contracts/:id', element: <ContractDetail /> },
  { path: '/deployments', element: <Deployments /> },
  { path: '/employees', element: <Employees /> },
  { path: '/employees/:id', element: <EmployeeProfilePlaceholder /> },
  { path: '/billing', element: <Billing /> },
  { path: '/contacts', element: <Contacts /> },
  { path: '/pipeline', element: <Pipeline /> },
  { path: '/opportunities', element: <Opportunities /> },
  { path: '/opportunities/new', element: <NewOpportunity /> },
  { path: '/opportunities/:id', element: <OpportunityDetail /> },
  { path: '/communications', element: <Communications /> },
  { path: '/communications/new', element: <NewCommunication /> },
  { path: '/communications/threads', element: <CommunicationThreads /> },
  { path: '/communications/threads/:threadId', element: <CommunicationThreadDetail /> },
  { path: '/communications/templates', element: <CommunicationTemplates /> },
  { path: '/communications/templates/new', element: <CommunicationTemplateForm /> },
  { path: '/communications/templates/:id', element: <CommunicationTemplateForm /> },
  { path: '/communications/:id', element: <CommunicationDetail /> },
  { path: '/follow-ups', element: <FollowUps /> },
  { path: '/follow-ups/new', element: <FollowUpForm /> },
  { path: '/follow-ups/:id', element: <FollowUpForm /> },
  { path: '/surveys', element: <Surveys /> },
  { path: '/surveys/new', element: <SurveyForm /> },
  { path: '/surveys/:id', element: <SurveyForm /> },
  { path: '/survey-invitations', element: <SurveyInvitations /> },
  { path: '/satisfaction', element: <Satisfaction /> },
  { path: '/satisfaction/responses', element: <SurveyResponses /> },
  { path: '/satisfaction/responses/:id', element: <SurveyResponseDetail /> },
  { path: '/reports', element: <Reports /> },
  // Module 08 S1 — personal settings for every role, then the guarded administration planes.
  { path: '/settings', element: <PersonalSettings /> },
  { path: '/admin', element: <AdminHome />, roles: ADMIN_ROLES, area: 'application' },
  { path: '/admin/users', element: <AdminUsers />, roles: ADMIN_ROLES, area: 'application' },
  { path: '/admin/teams', element: <AdminTeams />, roles: ADMIN_ROLES, area: 'application' },
  { path: '/admin/settings', element: <AdminSettings />, roles: ADMIN_ROLES, area: 'application' },
  { path: '/admin/audit', element: <AdminAudit />, roles: ADMIN_ROLES, area: 'application' },
  {
    path: '/admin/operations',
    element: <AdminOperations />,
    roles: ADMIN_ROLES,
    area: 'application',
  },
  { path: '/platform', element: <PlatformHome />, roles: PLATFORM_ROLES, area: 'platform' },
  {
    path: '/platform/diagnostics',
    element: <PlatformDiagnostics />,
    roles: PLATFORM_ROLES,
    area: 'platform',
  },
]
