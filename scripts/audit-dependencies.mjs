import { spawnSync } from 'node:child_process'
import { readFileSync, readdirSync } from 'node:fs'
import { resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

export function evaluateAudit(full, runtime, lock, policy, today = new Date().toISOString().slice(0, 10)) {
  for (const report of [full, runtime]) {
    if (!report || report.error || !report.vulnerabilities || typeof report.vulnerabilities !== 'object') {
      throw new Error('Audit report is unavailable or invalid; refusing to approve dependencies.')
    }
  }
  const errors = Object.keys(runtime.vulnerabilities).map(name => `Runtime dependency vulnerability: ${name}`)
  const accepted = new Set()
  const expired = !/^\d{4}-\d{2}-\d{2}$/.test(policy.expires ?? '') || today >= policy.expires
  for (const [name, finding] of Object.entries(full.vulnerabilities)) {
    if (!Array.isArray(finding.nodes) || !finding.nodes.length
      || finding.nodes.some(node => lock.packages?.[node]?.dev !== true)) {
      errors.push(`Affected dependency is not exclusively a development dependency: ${name}`)
      continue
    }
    const visit = (dependency, seen = new Set()) => {
      if (seen.has(dependency)) return
      seen.add(dependency)
      const item = full.vulnerabilities[dependency]
      if (!item || !Array.isArray(item.via) || !item.via.length) {
        errors.push(`Unresolved audit dependency chain: ${dependency}`)
        return
      }
      for (const via of item.via) {
        if (typeof via === 'string') {
          visit(via, seen)
          continue
        }
        const id = /\/advisories\/(GHSA-[a-z0-9-]+)$/.exec(via.url ?? '')?.[1]
        const exception = policy.advisories?.[id]
        if (expired || !exception || !exception.reason || exception.package !== via.name
          || item.fixAvailable !== false || !Array.isArray(item.nodes) || !item.nodes.length
          || item.nodes.some(node => {
            const pkg = lock.packages?.[node]
            return pkg?.dev !== true || !exception.versions.includes(pkg.version)
          })) {
          errors.push(`Blocking advisory: ${id || 'unknown'} (${dependency})${expired ? ' — policy expired' : ''}`)
        } else {
          accepted.add(id)
        }
      }
    }
    visit(name)
  }
  return { errors: [...new Set(errors)], accepted: [...accepted] }
}

export function verifyBundleSources(sources, policy) {
  const packages = Object.values(policy.advisories).map(entry => entry.package)
  return packages.filter(name => sources.some(source => source.replaceAll('\\', '/').includes(`/node_modules/${name}/`)))
}

function npmAudit(args) {
  const result = spawnSync('npm', ['audit', '--json', ...args], { encoding: 'utf8', maxBuffer: 16 * 1024 * 1024 })
  if (result.error || ![0, 1].includes(result.status)) throw new Error('npm audit could not complete.')
  return JSON.parse(result.stdout)
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  try {
    const policy = JSON.parse(readFileSync(new URL('./audit-policy.json', import.meta.url), 'utf8'))
    const lock = JSON.parse(readFileSync('package-lock.json', 'utf8'))
    const full = npmAudit([])
    const runtime = npmAudit(['--omit=dev'])
    const result = evaluateAudit(full, runtime, lock, policy)
    const modules = readdirSync('js').filter(name => name.endsWith('.mjs'))
    if (!modules.length) throw new Error('Build the browser assets before auditing the release.')
    for (const module of modules) {
      const map = JSON.parse(readFileSync(`js/${module}.map`, 'utf8'))
      if (!Array.isArray(map.sources) || !map.sources.length) throw new Error(`Missing source-map coverage: ${module}`)
      for (const pkg of verifyBundleSources(map.sources, policy)) result.errors.push(`Excepted development package is in browser bundle: ${pkg}`)
    }
    console.log('Full npm audit counts:', JSON.stringify(full.metadata?.vulnerabilities))
    console.log('Runtime npm audit counts:', JSON.stringify(runtime.metadata?.vulnerabilities))
    for (const id of result.accepted) console.log(`Temporary dev-tool exception until ${policy.expires}: ${id}`)
    if (result.errors.length) {
      for (const error of result.errors) console.error(error)
      process.exitCode = 1
    } else {
      console.log('Dependency policy passed.')
    }
  } catch (error) {
    console.error(error.message)
    process.exitCode = 1
  }
}
